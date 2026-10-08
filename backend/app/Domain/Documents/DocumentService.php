<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Documents\Models\ProcessDocument;
use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Projects\Models\SolarProject;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DocumentService
{
    public function hash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    public function validateValidityAndHash(ProcessDocument $document): bool
    {
        return $document->isValid() && $document->verifyHash();
    }

    /** @param array<string, mixed> $metadata */
    public function upload(SolarProject $project, ?HomologationProcess $process, UploadedFile $file, User $actor, array $metadata): ProcessDocument
    {
        $path = null;
        try {
            return DB::transaction(function () use ($project, $process, $file, $actor, $metadata, &$path) {
                $project = SolarProject::whereKey($project->id)->lockForUpdate()->firstOrFail();
                if ($process) {
                    abort_unless($process->solar_project_id === $project->id, 404);
                    $process->refresh();
                    if ($process->status->isTerminal()) {
                        throw new DomainException('Processo encerrado não aceita novos documentos.', 'process_closed');
                    }
                    if (! $process->status->isEditable() && ! in_array($metadata['document_type'], ['comprovante_envio', 'orcamento_conexao', 'relatorio_vistoria', 'evidencia_conexao', 'outro'], true)) {
                        throw new DomainException('Retorne o processo à preparação para substituir documentos do dossiê.', 'process_locked');
                    }
                } else {
                    $project->assertEditable();
                }
                $query = $project->documents()->where('homologation_process_id', $process?->id)->where('document_type', $metadata['document_type']);
                $previous = (clone $query)->orderByDesc('version')->first();
                $hash = $this->hash($file);
                if ($previous !== null && $previous->is_current && hash_equals($previous->sha256, $hash)) {
                    throw new DomainException('Este arquivo é idêntico à versão atual.', 'document_duplicated');
                }
                $path = $file->storeAs("tenants/{$project->tenant_id}/projects/{$project->uuid}", Str::uuid().'.'.$file->extension(), 'local');
                DocumentLink::whereIn('document_id', (clone $query)->select('id'))->update(['is_current' => false]);
                $query->update(['is_current' => false]);

                return ProcessDocument::create(['solar_project_id' => $project->id, 'homologation_process_id' => $process?->id, 'document_type' => $metadata['document_type'],
                    'version' => $previous !== null ? $previous->version + 1 : 1, 'is_current' => true, 'issued_at' => $metadata['issued_at'] ?? null, 'expires_at' => $metadata['expires_at'] ?? null,
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''), 'storage_path' => $path, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                    'sha256' => $hash, 'review_status' => 'pendente', 'uploaded_by' => $actor->id])->refresh();
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $error;
        }
    }
}
