<?php

namespace App\Http\Controllers\Api\Registry;

use App\Domain\Distributors\Enums\IntegrationMode;
use App\Domain\Distributors\Models\Distributor;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Registry\DistributorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class DistributorController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DistributorResource::collection(Distributor::query()->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('integrations.configure');

        foreach (['code', 'state'] as $field) {
            if (is_string($value = $request->input($field))) {
                $request->merge([$field => strtoupper(trim($value))]);
            }
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('distributors', 'code')
                ->where('tenant_id', app(TenantContext::class)->id())],
            'name' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'integration_mode' => ['sometimes', Rule::enum(IntegrationMode::class)],
            'portal_url' => ['nullable', 'url', 'max:255'],
            'secret_ref' => ['nullable', 'string', 'max:120', 'regex:/^[A-Z0-9_]+$/'],
            'active' => ['sometimes', 'boolean'],
        ], ['code.unique' => 'Já existe uma distribuidora com este código.']);

        $distributor = Distributor::create($data)->refresh();

        return DistributorResource::make($distributor)->response()->setStatusCode(201);
    }

    public function update(Request $request, Distributor $distributor): DistributorResource
    {
        $this->authorize('integrations.configure');

        $data = $request->validate([
            'integration_mode' => ['required', Rule::enum(IntegrationMode::class)],
            'portal_url' => ['nullable', 'url', 'max:255'],
            // Apenas o nome da referência no cofre; o valor do segredo nunca trafega por aqui.
            'secret_ref' => ['nullable', 'string', 'max:120', 'regex:/^[A-Z0-9_]+$/'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $distributor->update($data);

        return DistributorResource::make($distributor);
    }
}
