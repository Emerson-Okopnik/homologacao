<?php

namespace App\Http\Controllers\Api\Registry;

use App\Domain\Shared\Validation\TaxDocument;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\Registry\TechnicalResponsibleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TechnicalResponsibleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('projects.view');

        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $items = TechnicalResponsible::query()
            ->with('user')
            ->withCount('projects')
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$s}%")->orWhere('registration', 'like', "%{$s}%")))
            ->when(array_key_exists('active', $filters) && $filters['active'] !== null, fn ($q) => $q->where('active', (bool) $filters['active']))
            ->orderBy('name')->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return TechnicalResponsibleResource::collection($items);
    }

    public function users(): JsonResponse
    {
        $this->authorize('technical_responsibles.manage');

        return response()->json(['data' => User::query()->technicalStaff()->orderBy('name')->get(['uuid', 'name', 'email'])
            ->map(fn ($user) => ['id' => $user->uuid, 'name' => $user->name, 'email' => $user->email])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('technical_responsibles.manage');

        $item = TechnicalResponsible::create($this->validated($request));

        return TechnicalResponsibleResource::make($item->refresh())->response()->setStatusCode(201);
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
        if ($request->exists('cpf')) {
            $request->merge(['cpf' => TaxDocument::digits((string) $request->input('cpf')) ?: null]);
        }

        $data = $request->validate([
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'cpf' => ['nullable', 'digits:11', function ($attribute, $value, $fail) {
                if (! TaxDocument::isValid('PF', $value)) {
                    $fail('CPF inválido.');
                }
            }, Rule::unique('technical_responsibles')->where('tenant_id', app(TenantContext::class)->id())->ignore($current?->id)],
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

        // O RT é um funcionário: só usuários internos (sem vínculo de cliente) do tenant.
        if (array_key_exists('user_id', $data)) {
            if (filled($data['user_id'])) {
                $user = User::query()->technicalStaff()->where('uuid', $data['user_id'])->first();
                if (! $user) {
                    throw ValidationException::withMessages(['user_id' => 'Selecione um usuário interno ativo com permissão para trabalhar em projetos.']);
                }
                $data['user_id'] = $user->id;
            } else {
                $data['user_id'] = null;
            }
        }

        return $data;
    }
}
