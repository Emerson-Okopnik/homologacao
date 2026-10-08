<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Documents\DocumentRequirements;
use App\Domain\Documents\DocumentService;
use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Homologation\DocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentController extends Controller
{
    private const DISK = 'local';

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('documents.view');

        $filters = $request->validate([
            'review_status' => ['sometimes', 'nullable', Rule::in(['pendente', 'aprovado', 'reprovado'])],
            'type' => ['sometimes', 'nullable', Rule::in(array_keys(DocumentRequirements::TYPES))],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $docs = ProcessDocument::query()
            ->with(['process.project.client', 'project.client', 'uploader', 'reviewer'])
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

    public function versions(HomologationProcess $process, string $type): AnonymousResourceCollection
    {
        $this->authorize('documents.view');

        return DocumentResource::collection(
            $process->documents()->with(['uploader', 'reviewer'])->where('document_type', $type)->orderByDesc('version')->get(),
        );
    }

    public function store(Request $request, HomologationProcess $process): JsonResponse
    {
        $this->authorize('documents.manage');
        if (! $process->status->isEditable() && ! in_array($request->input('document_type'), ['comprovante_envio', 'orcamento_conexao', 'relatorio_vistoria', 'evidencia_conexao', 'outro'], true)) {
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
            'document_type' => ['required', Rule::in(array_keys(DocumentRequirements::TYPES))],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
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
        return response()->json([
            'data' => collect(DocumentRequirements::TYPES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }
}
