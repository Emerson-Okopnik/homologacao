<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Documents\DocumentTypes;
use App\Domain\Documents\DocumentUploader;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Homologations\Models\ConnectionEvent;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\Inspection;
use App\Domain\Homologations\Models\ProjectExecution;
use App\Domain\Homologations\TimelineRecorder;
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

/**
 * Documento existe uma vez e é vinculado à entidade dona (projeto, equipamento,
 * execução, vistoria, evento de conexão ou processo). Nova versão nunca apaga a anterior.
 */
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

        $docs = Document::query()
            ->with(['uploader', 'reviewer', 'links.linkable'])
            ->whereHas('links', fn ($q) => $q->where('is_current', true))
            ->when($filters['review_status'] ?? null, fn ($q, string $s) => $q->where('review_status', $s))
            ->when($filters['type'] ?? null, fn ($q, string $t) => $q->where('document_type', $t))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where('original_name', 'ilike', "%{$s}%"))
            ->latest('id')
            ->paginate(25);

        $docs->getCollection()->each(fn (Document $d) => $d->links->each(function ($l): void {
            if ($l->linkable instanceof SolarProject) {
                $l->linkable->loadMissing('client');
            }
        }));

        return DocumentResource::collection($docs);
    }

    public function types(): JsonResponse
    {
        return response()->json(['data' => DocumentTypes::options()]);
    }

    /** Histórico de versões de um tipo vinculado a uma entidade. */
    public function versions(Request $request): AnonymousResourceCollection
    {
        $this->authorize('documents.view');
        [$type, $owner] = $this->resolveOwner($request);
        $docType = $request->validate(['document_type' => ['required', Rule::in(array_keys(DocumentTypes::TYPES))]])['document_type'];

        $ids = DocumentLink::query()
            ->where('linkable_type', $type)->where('linkable_id', $owner->getKey())
            ->where('document_type', $docType)->pluck('document_id');

        return DocumentResource::collection(
            Document::query()->with(['uploader', 'reviewer', 'links'])->whereIn('id', $ids)->orderByDesc('version')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('documents.manage');
        [$type, $owner] = $this->resolveOwner($request);

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

        return DocumentResource::make($document->load(['uploader', 'links']))->response()->setStatusCode(201);
    }

    public function review(Request $request, Document $document): DocumentResource
    {
        $this->authorize('documents.manage');

        $data = $request->validate([
            'review_status' => ['required', Rule::in(['aprovado', 'reprovado'])],
            'review_notes' => ['required_if:review_status,reprovado', 'nullable', 'string', 'max:2000'],
        ], ['review_notes.required_if' => 'Informe o motivo da reprovação.']);

        if (! $document->links()->where('is_current', true)->exists()) {
            throw new DomainException('Somente a versão atual pode ser revisada.', 'document_not_current');
        }

        $document->forceFill([
            'review_status' => $data['review_status'],
            'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return DocumentResource::make($document->load(['uploader', 'reviewer', 'links']));
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('documents.view');

        abort_unless(Storage::disk(self::DISK)->exists($document->storage_path), 404, 'Arquivo não encontrado.');

        return Storage::disk(self::DISK)->download($document->storage_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{0: string, 1: Model}
     */
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
        return match ($type) {
            'project' => $owner->process,
            'process' => $owner,
            'execution', 'inspection', 'connection_event' => $owner->process,
            default => null,
        };
    }
}
