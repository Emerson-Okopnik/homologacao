<?php

namespace App\Http\Controllers\Api\Registry;

use App\Domain\Clients\Models\Client;
use App\Domain\ConsumerUnits\Models\ConsumerUnit;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Registry\ConsumerUnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class ConsumerUnitController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('clients.view');

        $filters = $request->validate([
            'client' => ['sometimes', 'nullable', 'uuid'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);

        $units = ConsumerUnit::query()
            ->with(['client', 'distributor'])
            ->when($filters['client'] ?? null, fn ($q, string $uuid) => $q->whereHas('client', fn ($c) => $c->where('uuid', $uuid)))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$s}%")
                ->orWhere('city', 'ilike', "%{$s}%")
                ->orWhere('street', 'ilike', "%{$s}%")))
            ->when(array_key_exists('active', $filters) && $filters['active'] !== null, fn ($q) => $q->where('active', (bool) $filters['active']))
            ->orderBy('number')
            ->paginate($filters['per_page'] ?? 20);

        return ConsumerUnitResource::collection($units);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('clients.manage');

        $data = $this->validated($request);
        $this->assertNotDuplicated($data);

        $unit = ConsumerUnit::create($data);

        return ConsumerUnitResource::make($unit->load(['client', 'distributor']))->response()->setStatusCode(201);
    }

    public function update(Request $request, ConsumerUnit $consumerUnit): ConsumerUnitResource
    {
        $this->authorize('clients.manage');

        $data = $this->validated($request);
        $this->assertNotDuplicated($data, $consumerUnit);

        $consumerUnit->update($data);

        return ConsumerUnitResource::make($consumerUnit->load(['client', 'distributor']));
    }

    /**
     * RN-03 / CT-02: distribuidora + número identifica uma UC ativa.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNotDuplicated(array $data, ?ConsumerUnit $current = null): void
    {
        if (($data['active'] ?? true) === false) {
            return;
        }

        $existing = ConsumerUnit::query()
            ->with('client')
            ->where('distributor_id', $data['distributor_id'])
            ->where('number', $data['number'])
            ->where('active', true)
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->first();

        if ($existing) {
            throw new DomainException(
                "A UC {$existing->number} já está cadastrada e ativa para o cliente {$existing->client->name}.",
                'consumer_unit_duplicated',
                409,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $request->merge([
            'number' => preg_replace('/\s/', '', (string) $request->input('number')),
            'zip' => preg_replace('/\D/', '', (string) $request->input('zip')) ?: null,
        ]);

        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
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
            'active' => ['sometimes', 'boolean'],
        ]);

        // Resolução via escopo do tenant: UUID de outro tenant resulta em 404.
        $data['client_id'] = Client::query()->where('uuid', $data['client_id'])->firstOrFail()->id;
        $data['distributor_id'] = Distributor::query()->where('uuid', $data['distributor_id'])->firstOrFail()->id;
        $data['state'] = strtoupper($data['state']);

        return $data;
    }
}
