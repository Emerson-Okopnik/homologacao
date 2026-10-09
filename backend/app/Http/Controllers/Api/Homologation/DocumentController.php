<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Documents\DocumentService;
use App\Domain\Documents\DocumentTypes;
use App\Domain\Documents\DocumentUploader;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\Models\ConnectionEvent;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\Inspection;
use App\Domain\Homologations\Models\ProjectExecution;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Homologation\DocumentResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentController extends Controller
{
    private const DISK = 'local';

    /** @var array<string, class-string<Model>> */
    private const OWNERS = [
        'project' => SolarProject::class,
        'equipment' => EquipmentItem::class,
        'execution' => ProjectExecution::class,
        'inspection' => Inspection::class,
        'connection_event' => ConnectionEvent::class,
        'process' => HomologationProcess::class,
    ];

    public function __construct(
        private readonly TimelineRecorder $timeline,
        private readonly DocumentUploader $uploader,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('documents.view');

        $filters = $request->validate([
            'review_status' => ['sometimes', 'nullable', Rule::in(['pendente', 'aprovado', 'reprovado'])],
            'type' => ['sometimes', 'nullable', Rule::in(array_keys(DocumentTypes::TYPES))],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $docs = ProcessDocument::query()
            ->with(['process.project.client', 'project.client', 'uploader', 'reviewer', 'links.linkable'])
            ->where('is_current', true)
            ->when($filters['review_status'] ?? null, fn ($q, string $s) => $q->where('review_status', $s))
            ->when($filters['type'] ?? null, fn ($q, string $t) => $q->where('document_type', $t))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('original_name', 'ilike', "%{$s}%")
                ->orWhereHas('process', fn ($p) => $p->where('code', 'ilike', "%{$s}%"))))
            ->latest('id')
            ->paginate(25);

        return DocumentResource::collection($docs);
    }

    public function versions(Request $request, ?HomologationProcess $process = null, ?string $type = null): AnonymousResourceCollection
    {
        $this->authorize('documents.view');

        if (! $process) {
            [$ownerType, $owner] = $this->resolveOwner($request);
            $type = $request->validate(['document_type' => ['required', Rule::in(array_keys(DocumentTypes::TYPES))]])['document_type'];
            $ids = DocumentLink::where('linkable_type', $ownerType)->where('linkable_id', $owner->getKey())->where('document_type', $type)->pluck('document_id');

            return DocumentResource::collection(ProcessDocument::whereIn('id', $ids)->with(['uploader', 'reviewer', 'links'])->orderByDesc('version')->get());
        }

        return DocumentResource::collection(
            $process->documents()->with(['uploader', 'reviewer'])->where('document_type', $type)->orderByDesc('version')->get(),
        );
    }

    public function store(Request $request, ?HomologationProcess $process = null): JsonResponse
    {
        $this->authorize('documents.manage');
        if (! $process) {
            [$type,$owner] = $this->resolveOwner($request);
            if ($owner instanceof HomologationProcess) {
                return $this->store($request, $owner);
            }
            if ($owner instanceof SolarProject) {
                return $this->storeProject($request, $owner);
            }

            return $this->uploadForOwner($request, $type, $owner);
        }
        if (! $process->status->isEditable() && ! in_array(DocumentTypes::legacy((string) $request->input('document_type')), ['comprovante_envio', 'orcamento_conexao', 'relatorio_vistoria', 'evidencia_conexao', 'outro'], true)) {
            throw new DomainException("Documentos não podem ser enviados com o processo em \"{$process->status->label()}\".", 'process_locked');
        }

        return $this->upload($request, $process->project, $process);
    }

    public function storeProject(Request $request, SolarProject $project): JsonResponse
    {
        $this->authorize('documents.manage');
        $project->assertEditable();

        return $this->upload($request, $project);
    }

    private function upload(Request $request, SolarProject $project, ?HomologationProcess $process = null): JsonResponse
    {

        $data = $request->validate([
            'document_type' => ['required', Rule::in(array_keys(DocumentTypes::TYPES))],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ]);

        $this->assertCanAttach($type, $owner);

        $document = DB::transaction(function () use ($type, $owner, $data, $request): Document {
            $document = $this->uploader->upload($type, $owner, $data['document_type'], $request->file('file'), $request->user()->id);

            if ($process = $this->processOf($type, $owner)) {
                $this->timeline->record($process, 'DOCUMENT_UPLOADED',
                    DocumentTypes::label($data['document_type'])." v{$document->version} enviado", $document->original_name);
            }

            return $document;
        });
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'], 'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
        ]);

        $document = app(DocumentService::class)->upload($project, $process, $request->file('file'), $request->user(), $data);

        return DocumentResource::make($document->refresh()->load(['uploader', 'reviewer']))->response()->setStatusCode(201);
    }

    public function review(Request $request, ProcessDocument $document): DocumentResource
    {
        $this->authorize('documents.manage');

        $data = $request->validate([
            'review_status' => ['required', Rule::in(['aprovado', 'reprovado'])],
            'review_notes' => ['required_if:review_status,reprovado', 'nullable', 'string', 'max:2000'],
        ], ['review_notes.required_if' => 'Informe o motivo da reprovação.']);

        if (! $document->is_current) {
            throw new DomainException('Somente a versão atual pode ser revisada.', 'document_not_current');
        }
        if ($data['review_status'] === 'aprovado' && (! $document->verifyHash() || ($document->expires_at && $document->expires_at->isBefore(today())))) {
            throw new DomainException('O arquivo está ausente, divergente ou vencido.', 'document_invalid');
        }

        $document->forceFill([
            'review_status' => $data['review_status'],
            'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return DocumentResource::make($document->load(['uploader', 'reviewer']));
    }

    public function download(ProcessDocument $document): StreamedResponse
    {
        $this->authorize('documents.view');

        abort_unless(Storage::disk(self::DISK)->exists($document->storage_path), 404, 'Arquivo não encontrado.');
        if (! $document->verifyHash()) {
            throw new DomainException('O conteúdo do arquivo diverge do hash registrado.', 'document_integrity_failed', 409);
        }

        return Storage::disk(self::DISK)->download($document->storage_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function types(): JsonResponse
    {
        return response()->json(['data' => DocumentTypes::options()]);
    }

    private function uploadForOwner(Request $request, string $type, Model $owner): JsonResponse
    {
        $this->assertCanAttach($type, $owner);
        $data = $request->validate(['document_type' => ['required', Rule::in(array_keys(DocumentTypes::TYPES))], 'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'], 'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at']]);
        $path = null;
        try {
            $document = DB::transaction(function () use ($request, $type, $owner, $data, &$path) {
                $owner->newQuery()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
                $links = DocumentLink::where('linkable_type', $type)->where('linkable_id', $owner->getKey())->where('document_type', $data['document_type']);
                $previous = (clone $links)->with('document')->latest('id')->first()?->document;
                $file = $request->file('file');
                $hash = app(DocumentService::class)->hash($file);
                if ($previous && hash_equals($previous->sha256, $hash)) {
                    throw new DomainException('Este arquivo é idêntico à versão atual.', 'document_duplicated');
                }
                $path = $file->storeAs('tenants/'.$owner->getAttribute('tenant_id').'/'.$type.'/'.$owner->getAttribute('uuid'), Str::uuid().'.'.$file->extension(), self::DISK);
                $links->update(['is_current' => false]);
                if ($previous) {
                    $previous->update(['is_current' => false]);
                }
                $document = Document::create(['document_type' => $data['document_type'], 'version' => $previous ? $previous->version + 1 : 1, 'supersedes_document_id' => $previous?->id,
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''), 'storage_path' => $path, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'sha256' => $hash,
                    'is_current' => true, 'review_status' => 'pendente', 'uploaded_by' => $request->user()->id, 'issued_at' => $data['issued_at'] ?? null, 'expires_at' => $data['expires_at'] ?? null]);
                $links->create(['document_id' => $document->id, 'linkable_type' => $type, 'linkable_id' => $owner->getKey(), 'document_type' => $data['document_type'], 'is_current' => true, 'linked_by' => $request->user()->id]);

                return $document;
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $error;
        }

        return DocumentResource::make($document->refresh()->load(['uploader', 'reviewer', 'links']))->response()->setStatusCode(201);
    }

    /** @return array{string, Model} */
    private function resolveOwner(Request $request): array
    {
        $data = $request->validate([
            'owner_type' => ['required', Rule::in(array_keys(self::OWNERS))],
            'owner_id' => ['required', 'uuid'],
        ]);

        $owner = self::OWNERS[$data['owner_type']]::query()->where('uuid', $data['owner_id'])->firstOrFail();

        return [$data['owner_type'], $owner];
    }

    private function assertCanAttach(string $type, Model $owner): void
    {
        $process = $this->processOf($type, $owner);
        if ($process && ! $process->isActive()) {
            throw new DomainException('O processo não está em andamento.', 'process_not_active');
        }
        if ($type === 'project' && $process && ! $process->stage->allowsProjectEdit()) {
            throw new DomainException(
                "Documentos do projeto não podem ser alterados na etapa \"{$process->stage->label()}\".",
                'project_locked',
            );
        }
    }

    private function processOf(string $type, Model $owner): ?HomologationProcess
    {
        if ($owner instanceof HomologationProcess) {
            return $owner;
        }
        if (in_array($type, ['project', 'execution', 'inspection', 'connection_event'], true)) {
            /** @var HomologationProcess|null $process */
            $process = $owner->getRelationValue('process');

            return $process;
        }

        return null;
    }
}
