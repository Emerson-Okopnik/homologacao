<?php

namespace App\Http\Controllers\Api\Registry;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientContact;
use App\Domain\Shared\Validation\TaxDocument;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Registry\ClientContactResource;
use App\Http\Resources\Registry\ClientResource;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('clients.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'type' => ['sometimes', 'nullable', Rule::in(['PF', 'PJ'])],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);

        $clients = Client::query()
            ->withCount(['consumerUnits', 'projects'])
            ->when($filters['search'] ?? null, function ($q, string $search): void {
                $digits = TaxDocument::digits($search);
                $q->where(function ($w) use ($search, $digits): void {
                    $w->where('name', 'ilike', "%{$search}%")->orWhere('trade_name', 'ilike', "%{$search}%");
                    if ($digits !== '') {
                        $w->orWhere('document', 'like', "%{$digits}%");
                    }
                });
            })
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20);

        return ClientResource::collection($clients);
    }

    public function show(Client $client): ClientResource
    {
        $this->authorize('clients.view');

        return ClientResource::make($client->load(['contacts', 'consumerUnits.distributor'])->loadCount(['consumerUnits', 'projects']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('clients.manage');

        $client = Client::create($this->validated($request));

        return ClientResource::make($client)->response()->setStatusCode(201);
    }

    public function update(Request $request, Client $client): ClientResource
    {
        $this->authorize('clients.manage');

        $client->update($this->validated($request, $client));

        return ClientResource::make($client->loadCount(['consumerUnits', 'projects']));
    }

    public function storeContact(Request $request, Client $client): JsonResponse
    {
        $this->authorize('clients.manage');

        $contact = new ClientContact($this->validatedContact($request));
        $contact->client()->associate($client);
        $contact->save();

        return ClientContactResource::make($contact)->response()->setStatusCode(201);
    }

    public function updateContact(Request $request, ClientContact $contact): ClientContactResource
    {
        $this->authorize('clients.manage');

        $contact->update($this->validatedContact($request));

        return ClientContactResource::make($contact);
    }

    public function destroyContact(ClientContact $contact): Response
    {
        $this->authorize('clients.manage');

        $contact->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Client $client = null): array
    {
        $request->merge(['document' => TaxDocument::digits($request->input('document'))]);

        $data = $request->validate([
            'type' => ['required', Rule::in(['PF', 'PJ'])],
            'document' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                    if (! TaxDocument::isValid((string) $request->input('type'), (string) $value)) {
                        $fail($request->input('type') === 'PJ' ? 'CNPJ inválido.' : 'CPF inválido.');
                    }
                },
                // RN-02: documento único dentro do tenant (inclui inativos/excluídos logicamente).
                Rule::unique('clients', 'document')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($client?->id),
            ],
            'name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'document.unique' => 'Já existe um cliente com este CPF/CNPJ.',
        ]);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedContact(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['nullable', 'string', 'max:80'],
            'is_legal_representative' => ['sometimes', 'boolean'],
        ]);
    }
}
