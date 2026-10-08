<?php

namespace App\Http\Controllers\Api\Registry;

use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Registry\TechnicalResponsibleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class TechnicalResponsibleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('projects.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $items = TechnicalResponsible::query()
            ->withCount('projects')
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$s}%")->orWhere('registration', 'like', "%{$s}%")))
            ->when(array_key_exists('active', $filters) && $filters['active'] !== null, fn ($q) => $q->where('active', (bool) $filters['active']))
            ->orderBy('name')
            ->paginate(50);

        return TechnicalResponsibleResource::collection($items);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('technical_responsibles.manage');

        $item = TechnicalResponsible::create($this->validated($request));

        return TechnicalResponsibleResource::make($item)->response()->setStatusCode(201);
    }

    public function update(Request $request, TechnicalResponsible $technicalResponsible): TechnicalResponsibleResource
    {
        $this->authorize('technical_responsibles.manage');

        $technicalResponsible->update($this->validated($request, $technicalResponsible));

        return TechnicalResponsibleResource::make($technicalResponsible);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?TechnicalResponsible $current = null): array
    {
        $request->merge(['state' => strtoupper((string) $request->input('state'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'council' => ['required', Rule::in(['CREA', 'CFT'])],
            'registration' => [
                'required', 'string', 'max:40',
                Rule::unique('technical_responsibles')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->where('council', $request->input('council'))
                    ->where('state', $request->input('state'))
                    ->ignore($current?->id),
            ],
            'state' => ['required', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'registration_status' => ['required', Rule::in(['regular', 'irregular', 'nao_verificado'])],
            'active' => ['sometimes', 'boolean'],
        ], ['registration.unique' => 'Já existe um responsável com este registro neste conselho/UF.']);
    }
}
