<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grava um arquivo como nova versão de um tipo vinculado a uma entidade.
 * Nova versão nunca apaga a anterior; arquivo idêntico à versão atual é rejeitado.
 */
final class DocumentUploader
{
    public const DISK = 'local';

    public function upload(string $ownerType, Model $owner, string $documentType, UploadedFile $file, int $userId): Document
    {
        $hash = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($ownerType, $owner, $documentType, $file, $hash, $userId): Document {
            $currentLink = DocumentLink::query()
                ->where('linkable_type', $ownerType)->where('linkable_id', $owner->getKey())
                ->where('document_type', $documentType)->where('is_current', true)
                ->lockForUpdate()->first();
            $previous = $currentLink?->document;

            if ($previous && $previous->sha256 === $hash) {
                throw new DomainException('Este arquivo é idêntico à versão atual.', 'document_duplicated');
            }

            $path = $file->storeAs(
                'tenants/'.$owner->getAttribute('tenant_id')."/{$ownerType}/".$owner->getAttribute('uuid'),
                Str::uuid()->toString().'.'.$file->extension(),
                self::DISK,
            );

            $document = Document::create([
                'document_type' => $documentType,
                'version' => ($previous?->version ?? 0) + 1,
                'supersedes_document_id' => $previous?->id,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'storage_path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'sha256' => $hash,
                'review_status' => 'pendente',
                'uploaded_by' => $userId,
            ]);

            $currentLink?->update(['is_current' => false]);
            DocumentLink::create([
                'document_id' => $document->id,
                'linkable_type' => $ownerType,
                'linkable_id' => $owner->getKey(),
                'document_type' => $documentType,
                'is_current' => true,
                'linked_by' => $userId,
            ]);

            return $document;
        });
    }

    /**
     * Vincula os documentos atuais de uma entidade a outra (ex.: solicitação → projeto),
     * sem duplicar arquivo. Tipos que o destino já possui são mantidos.
     */
    public function copyCurrentLinks(string $fromType, Model $from, string $toType, Model $to, int $userId): int
    {
        $existing = DocumentLink::query()
            ->where('linkable_type', $toType)->where('linkable_id', $to->getKey())
            ->where('is_current', true)->pluck('document_type')->all();

        $links = DocumentLink::query()
            ->where('linkable_type', $fromType)->where('linkable_id', $from->getKey())
            ->where('is_current', true)
            ->whereNotIn('document_type', $existing)
            ->get();

        foreach ($links as $link) {
            DocumentLink::create([
                'document_id' => $link->document_id,
                'linkable_type' => $toType,
                'linkable_id' => $to->getKey(),
                'document_type' => $link->document_type,
                'is_current' => true,
                'linked_by' => $userId,
            ]);
        }

        return $links->count();
    }
}
