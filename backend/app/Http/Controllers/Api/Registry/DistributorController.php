<?php

namespace App\Http\Controllers\Api\Registry;

use App\Domain\Distributors\Enums\IntegrationMode;
use App\Domain\Distributors\Models\Distributor;
use App\Http\Controllers\Controller;
use App\Http\Resources\Registry\DistributorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class DistributorController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DistributorResource::collection(Distributor::query()->orderBy('name')->get());
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
