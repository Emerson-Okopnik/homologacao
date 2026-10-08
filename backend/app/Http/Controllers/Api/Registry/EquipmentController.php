<?php

namespace App\Http\Controllers\Api\Registry;

use App\Domain\Catalog\Models\EquipmentItem;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Registry\EquipmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class EquipmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('projects.view');

        $filters = $request->validate([
            'type' => ['sometimes', 'nullable', Rule::in(EquipmentItem::TYPES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $items = EquipmentItem::query()
            ->when($filters['type'] ?? null, fn ($q, string $t) => $q->where('type', $t))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('manufacturer', 'ilike', "%{$s}%")->orWhere('model', 'ilike', "%{$s}%")))
            ->when(array_key_exists('active', $filters) && $filters['active'] !== null, fn ($q) => $q->where('active', (bool) $filters['active']))
            ->orderBy('type')->orderBy('manufacturer')->orderBy('model')
            ->paginate(100);

        return EquipmentResource::collection($items);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('projects.manage');

        $item = EquipmentItem::create($this->validated($request));

        return EquipmentResource::make($item)->response()->setStatusCode(201);
    }

    public function update(Request $request, EquipmentItem $equipment): EquipmentResource
    {
        $this->authorize('projects.manage');

        $equipment->update($this->validated($request, $equipment));

        return EquipmentResource::make($equipment);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?EquipmentItem $current = null): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(EquipmentItem::TYPES)],
            'manufacturer' => ['required', 'string', 'max:120'],
            'model' => [
                'required', 'string', 'max:120',
                Rule::unique('equipment_catalog')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->where('type', $request->input('type'))
                    ->where('manufacturer', $request->input('manufacturer'))
                    ->ignore($current?->id),
            ],
            'power_w' => ['nullable', 'numeric', 'min:0'],
            'energy_kwh' => ['nullable', 'numeric', 'min:0'],
            'efficiency' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'certification' => ['nullable', 'string', 'max:120'],
            'nominal_ac_power_kw' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'has_inmetro_registration' => ['sometimes', 'boolean'],
            'inmetro_registration_number' => ['nullable', 'string', 'max:80'],
            'active' => ['sometimes', 'boolean'],
        ], ['model.unique' => 'Este equipamento já está no catálogo.']);
    }
}
