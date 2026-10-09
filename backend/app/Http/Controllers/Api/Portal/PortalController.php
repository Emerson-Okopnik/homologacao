<?php

namespace App\Http\Controllers\Api\Portal;

use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Documents\DocumentTypes;
use App\Domain\Documents\DocumentUploader;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Homologations\TimelineRecorder;
use App\Domain\Projects\ClientObligations;
use App\Domain\Projects\Enums\ClientRequestStatus;
use App\Domain\Projects\Enums\CompensationMode;
use App\Domain\Projects\Models\ClientRequest;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\SequentialCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Homologation\ClientRequestResource;
use App\Http\Resources\Registry\ConsumerUnitResource;
use App\Http\Resources\Registry\DistributorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal do cliente (dono do sistema): abre a solicitação, informa UC e
 * equipamentos, envia os documentos que são obrigação dele e acompanha o andamento.
 * Toda consulta é restrita ao client_id do usuário logado.
 */
final class PortalController extends Controller
{
    private const RELATIONS = ['client', 'consumerUnit.distributor', 'technicalResponsible', 'project.process'];

    public function __construct(
        private readonly DocumentUploader $uploader,
        private readonly TimelineRecorder $timeline,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        $requests = ClientRequest::query()->where('client_id', $clientId)->get();

        return response()->json(['data' => [
            'client' => $request->user()->client()->first(['uuid', 'name', 'email', 'phone'])?->only(['uuid', 'name', 'email', 'phone']),
            'requests_total' => $requests->count(),
            'requests_open' => $requests->filter(fn ($r) => $r->status->isOpen())->count(),
            'needs_info' => $requests->where('status', ClientRequestStatus::NeedsInfo)->count(),
            'converted' => $requests->where('status', ClientRequestStatus::Converted)->count(),
            'units' => ConsumerUnit::query()->where('client_id', $clientId)->where('active', true)->count(),
        ]]);
    }

    public function distributors(): AnonymousResourceCollection
    {
        return DistributorResource::collection(Distributor::query()->where('active', true)->orderBy('name')->get());
    }

    public function units(Request $request): AnonymousResourceCollection
    {
        return ConsumerUnitResource::collection(
            ConsumerUnit::query()->with(['client', 'distributor'])
                ->where('client_id', $this->clientId($request))
                ->where('active', true)->orderBy('number')->get(),
        );
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $request->merge([
            'number' => preg_replace('/\s/', '', (string) $request->input('number')),
            'zip' => preg_replace('/\D/', '', (string) $request->input('zip')) ?: null,
            'state' => strtoupper((string) $request->input('state')),
        ]);

        $data = $request->validate([
            'distributor_id' => ['required', 'uuid'],
            'number' => ['required', 'string', 'max:40'],
            'street' => ['required', 'string', 'max:200'],
            'address_number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'size:2'],
            'zip' => ['nullable', 'digits:8'],
            'voltage_class' => ['required', Rule::in(['BT', 'MT'])],
            'supply_type' => ['required', Rule::in(['monofasico', 'bifasico', 'trifasico'])],
            'installed_load_kw' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'contracted_demand_kw' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'breaker_a' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $distributor = Distributor::query()->where('uuid', $data['distributor_id'])->where('active', true)->firstOrFail();

        $duplicated = ConsumerUnit::query()
            ->where('distributor_id', $distributor->id)->where('number', $data['number'])->where('active', true)
            ->exists();
        if ($duplicated) {
            throw new DomainException('Esta unidade consumidora já está cadastrada. Fale com a equipe se ela for sua.', 'consumer_unit_duplicated');
        }

        $unit = ConsumerUnit::create([
            ...$data,
            'client_id' => $this->clientId($request),
            'distributor_id' => $distributor->id,
            'active' => true,
        ]);

        return ConsumerUnitResource::make($unit->load(['client', 'distributor']))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ClientRequestResource::collection(
            ClientRequest::query()->with(self::RELATIONS)
                ->where('client_id', $this->clientId($request))
                ->latest('id')->get(),
        );
    }

    public function show(Request $request, ClientRequest $clientRequest): ClientRequestResource
    {
        $this->assertOwn($request, $clientRequest);

        return ClientRequestResource::make($clientRequest->load(self::RELATIONS));
    }

    /** Lista prévia dos documentos do cliente, para a tela de abertura. */
    public function obligations(Request $request): JsonResponse
    {
        $system = $request->validate(['system' => ['array']])['system'] ?? [];

        return response()->json(['data' => ClientObligations::documentsFor($system)]);
    }

    public function store(Request $request): JsonResponse
    {
        [$unit, $system] = $this->validated($request);

        $clientRequest = DB::transaction(function () use ($request, $unit, $system): ClientRequest {
            $clientRequest = ClientRequest::create([
                'client_id' => $this->clientId($request),
                'consumer_unit_id' => $unit->id,
                'code' => SequentialCode::next(ClientRequest::class, 'SOL'),
                'system' => $system,
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
            ]);

            if ($note = trim((string) ($system['notes'] ?? ''))) {
                $clientRequest->addMessage('client', $request->user()->name, $note);
                $clientRequest->save();
            }

            return $clientRequest;
        });

        return ClientRequestResource::make($clientRequest->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function update(Request $request, ClientRequest $clientRequest): ClientRequestResource
    {
        $this->assertOwn($request, $clientRequest);
        if (! $clientRequest->status->editableByClient()) {
            throw new DomainException('Esta solicitação já virou projeto e não pode mais ser alterada pelo portal.', 'request_locked');
        }

        [$unit, $system] = $this->validated($request);

        $clientRequest->forceFill(['consumer_unit_id' => $unit->id, 'system' => $system]);
        if ($clientRequest->status === ClientRequestStatus::NeedsInfo) {
            $clientRequest->status = ClientRequestStatus::InReview;
            $clientRequest->addMessage('system', 'Sistema', 'O cliente atualizou os dados da solicitação.');
        }
        $clientRequest->save();

        return ClientRequestResource::make($clientRequest->refresh()->load(self::RELATIONS));
    }

    public function message(Request $request, ClientRequest $clientRequest): ClientRequestResource
    {
        $this->assertOwn($request, $clientRequest);
        $text = $request->validate(['text' => ['required', 'string', 'max:2000']])['text'];

        $clientRequest->addMessage('client', $request->user()->name, $text);
        if ($clientRequest->status === ClientRequestStatus::NeedsInfo) {
            $clientRequest->status = ClientRequestStatus::InReview;
        }
        $clientRequest->save();

        return ClientRequestResource::make($clientRequest->load(self::RELATIONS));
    }

    /**
     * Documento do cliente. Antes da conversão fica na solicitação; depois vai
     * direto para o projeto, para o RT enxergar sem retrabalho.
     */
    public function upload(Request $request, ClientRequest $clientRequest): JsonResponse
    {
        $this->assertOwn($request, $clientRequest);
        if ($clientRequest->status === ClientRequestStatus::Cancelled) {
            throw new DomainException('Solicitação cancelada.', 'request_cancelled');
        }

        $data = $request->validate([
            'document_type' => ['required', Rule::in(DocumentTypes::ofParty(DocumentTypes::PARTY_CLIENT))],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ]);

        $project = $clientRequest->project;
        $document = $project
            ? $this->uploader->upload('project', $project, $data['document_type'], $request->file('file'), $request->user()->id)
            : $this->uploader->upload('client_request', $clientRequest, $data['document_type'], $request->file('file'), $request->user()->id);

        if ($process = $project?->process) {
            $this->timeline->record($process, 'DOCUMENT_UPLOADED',
                DocumentTypes::label($data['document_type'])." v{$document->version} enviado pelo cliente", $document->original_name);
        }

        return ClientRequestResource::make($clientRequest->refresh()->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $clientId = $this->clientId($request);
        $requests = ClientRequest::query()->where('client_id', $clientId)->get(['id', 'solar_project_id']);

        $allowed = DocumentLink::query()
            ->where('document_id', $document->id)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('linkable_type', 'client_request')->whereIn('linkable_id', $requests->pluck('id')))
                ->orWhere(fn ($w) => $w->where('linkable_type', 'project')->whereIn('linkable_id', $requests->pluck('solar_project_id')->filter())))
            ->exists();

        abort_unless($allowed && in_array($document->document_type, DocumentTypes::ofParty(DocumentTypes::PARTY_CLIENT), true), 404);
        abort_unless(Storage::disk(DocumentUploader::DISK)->exists($document->storage_path), 404, 'Arquivo não encontrado.');

        return Storage::disk(DocumentUploader::DISK)->download($document->storage_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{0: ConsumerUnit, 1: array<string, mixed>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'consumer_unit_id' => ['required', 'uuid'],
            'system' => ['required', 'array'],
            'system.compensation_mode' => ['required', Rule::enum(CompensationMode::class)],
            'system.average_consumption_kwh' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'system.is_property_owner' => ['required', 'boolean'],
            'system.installation_type' => ['required', Rule::in(['TELHADO', 'LAJE', 'SOLO', 'CARPORT', 'FACHADA'])],
            'system.roof_material' => ['nullable', 'string', 'max:60'],
            'system.installation_area_m2' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'system.integrator' => ['nullable', 'string', 'max:160'],
            'system.modules' => ['required', 'array', 'min:1', 'max:10'],
            'system.modules.*.brand' => ['required', 'string', 'max:80'],
            'system.modules.*.model' => ['required', 'string', 'max:120'],
            'system.modules.*.power_w' => ['required', 'numeric', 'min:10', 'max:2000'],
            'system.modules.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'system.inverters' => ['required', 'array', 'min:1', 'max:10'],
            'system.inverters.*.brand' => ['required', 'string', 'max:80'],
            'system.inverters.*.model' => ['required', 'string', 'max:120'],
            'system.inverters.*.power_kw' => ['required', 'numeric', 'min:0.1', 'max:100000'],
            'system.inverters.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'system.has_battery' => ['sometimes', 'boolean'],
            'system.storage_energy_kwh' => ['nullable', 'required_if:system.has_battery,true', 'numeric', 'min:0', 'max:100000'],
            'system.beneficiaries' => ['array', 'max:50'],
            'system.beneficiaries.*.uc_number' => ['required', 'string', 'max:40'],
            'system.beneficiaries.*.holder_name' => ['nullable', 'string', 'max:160'],
            'system.beneficiaries.*.percentage' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'system.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $unit = ConsumerUnit::query()
            ->where('uuid', $data['consumer_unit_id'])
            ->where('client_id', $this->clientId($request))
            ->where('active', true)->first();
        if (! $unit) {
            throw new DomainException('Unidade consumidora não encontrada entre as suas.', 'unit_invalid');
        }

        $system = $data['system'];
        if (($system['compensation_mode'] ?? null) === CompensationMode::LocalSelfConsumption->value) {
            $system['beneficiaries'] = [];
        }
        $total = collect($system['beneficiaries'] ?? [])->sum(fn ($b) => (float) ($b['percentage'] ?? 0));
        if ($total > 100.0001) {
            throw new DomainException('A soma dos percentuais das UCs beneficiárias não pode passar de 100%.', 'allocation_over_100');
        }
        $system['has_battery'] = (bool) ($system['has_battery'] ?? false);

        return [$unit, $system];
    }

    private function clientId(Request $request): int
    {
        return (int) $request->user()->getAttribute('client_id');
    }

    private function assertOwn(Request $request, ClientRequest $clientRequest): void
    {
        abort_unless($clientRequest->client_id === $this->clientId($request), 404);
    }
}
