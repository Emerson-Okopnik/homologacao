<?php

namespace App\Http\Resources\Homologation;

use App\Domain\Documents\DocumentTypes;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Projects\ClientObligations;
use App\Domain\Projects\Models\ClientRequest;
use App\Http\Resources\Registry\ConsumerUnitResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Solicitação do cliente. Separa explicitamente as obrigações do cliente
 * (documentos do titular) das obrigações do responsável técnico.
 *
 * @mixin ClientRequest
 */
final class ClientRequestResource extends JsonResource
{
    public bool $forStaff = false;

    public static function forStaff(ClientRequest $request): self
    {
        $resource = new self($request);
        $resource->forStaff = true;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $documents = $this->currentDocuments();
        $byType = $documents->keyBy('document_type');

        $clientObligations = array_map(fn (array $o) => [
            ...$o,
            'document' => isset($byType[$o['type']]) ? $this->documentArray($byType[$o['type']]) : null,
        ], ClientObligations::documentsFor($this->system ?? []));

        $process = $this->project?->process;

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'editable' => $this->status->editableByClient(),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->uuid,
                'name' => $this->client->name,
                'document' => $this->client->document,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
            ]),
            'consumer_unit' => $this->whenLoaded('consumerUnit', fn () => ConsumerUnitResource::make($this->consumerUnit)->toArray($request)),
            'system' => $this->system,
            'declared_powers' => $this->declaredPowers(),
            'messages' => $this->messages ?? [],
            'technical_responsible' => $this->technicalResponsible ? [
                'id' => $this->technicalResponsible->uuid,
                'name' => $this->technicalResponsible->name,
                'council' => $this->technicalResponsible->council,
                'registration' => $this->technicalResponsible->registration,
                'email' => $this->technicalResponsible->email,
                'phone' => $this->technicalResponsible->phone,
            ] : null,
            'project' => $this->project ? [
                'id' => $this->project->uuid,
                'code' => $this->project->code,
                'process' => $process ? [
                    'id' => $process->uuid,
                    'code' => $process->code,
                    'stage' => ['value' => $process->stage?->value, 'label' => $process->stage?->label()],
                    'status' => ['value' => $process->status?->value, 'label' => method_exists($process->status, 'label') ? $process->status->label() : $process->status?->value],
                ] : null,
            ] : null,
            'client_obligations' => $clientObligations,
            'client_progress' => [
                'required' => collect($clientObligations)->where('required', true)->count(),
                'sent' => collect($clientObligations)->where('required', true)->filter(fn ($o) => $o['document'] !== null)->count(),
            ],
            'technical_obligations' => $this->when($this->forStaff, fn () => self::technicalObligations()),
            'documents' => $documents->map(fn (Document $d) => $this->documentArray($d))->values(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * O que fica com o RT depois da triagem. Os itens exatos por projeto vêm das
     * regras versionadas; aqui é a lista de referência para planejar o trabalho.
     *
     * @return list<array{type: string, label: string}>
     */
    public static function technicalObligations(): array
    {
        $types = ['ACCESS_REQUEST_FORM', 'PROJECT_ART', 'SINGLE_LINE_DIAGRAM', 'DESCRIPTIVE_MEMORIAL',
            'MODULE_DATASHEET', 'INVERTER_DATASHEET', 'INMETRO_CERTIFICATE', 'EXECUTION_ART', 'EXECUTION_PHOTOS'];

        return array_map(fn (string $t) => ['type' => $t, 'label' => DocumentTypes::label($t)], $types);
    }

    /**
     * Antes da conversão os documentos ficam na solicitação; depois, no projeto.
     *
     * @return \Illuminate\Support\Collection<int, Document>
     */
    private function currentDocuments(): \Illuminate\Support\Collection
    {
        [$type, $id] = $this->solar_project_id ? ['project', $this->solar_project_id] : ['client_request', $this->id];
        $clientTypes = DocumentTypes::ofParty(DocumentTypes::PARTY_CLIENT);

        $ids = DocumentLink::query()
            ->where('linkable_type', $type)->where('linkable_id', $id)
            ->where('is_current', true)
            ->when(! $this->forStaff, fn ($q) => $q->whereIn('document_type', $clientTypes))
            ->pluck('document_id');

        return Document::query()->whereIn('id', $ids)->orderBy('document_type')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function documentArray(Document $d): array
    {
        return [
            'id' => $d->uuid,
            'document_type' => $d->document_type,
            'label' => DocumentTypes::label($d->document_type),
            'party' => DocumentTypes::party($d->document_type),
            'original_name' => $d->original_name,
            'version' => $d->version,
            'review_status' => $d->review_status,
            'review_notes' => $d->getAttribute('review_notes'),
            'size_bytes' => $d->size_bytes,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
