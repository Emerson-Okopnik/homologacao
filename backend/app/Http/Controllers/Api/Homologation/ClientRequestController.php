<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Enums\ClientRequestStatus;
use App\Domain\Projects\Models\ClientRequest;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\TechnicalResponsibles\Models\TechnicalResponsible;
use App\Domain\Users\Actions\CreateUser;
use App\Domain\Users\Enums\SystemRole;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\Homologation\ClientRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Triagem pela equipe: atribui o RT (funcionário), pede informações ao cliente
 * e acompanha até a conversão em projeto (feita pelo formulário de projeto).
 */
final class ClientRequestController extends Controller
{
    private const RELATIONS = ['client', 'consumerUnit.distributor', 'technicalResponsible', 'project.process'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('projects.view');

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(ClientRequestStatus::class)],
            'mine' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $items = ClientRequest::query()->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->when($request->boolean('mine'), fn ($q) => $q->whereHas('technicalResponsible', fn ($t) => $t->where('user_id', $request->user()->id)))
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$s}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'ilike', "%{$s}%"))
                ->orWhereHas('consumerUnit', fn ($u) => $u->where('number', 'like', "%{$s}%"))))
            ->latest('id')->limit(200)->get();

        $counts = ClientRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'data' => $items->map(fn ($r) => ClientRequestResource::forStaff($r)->toArray($request))->values(),
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, ClientRequest $clientRequest): ClientRequestResource
    {
        $this->authorize('projects.view');

        return ClientRequestResource::forStaff($clientRequest->load(self::RELATIONS));
    }

    public function assign(Request $request, ClientRequest $clientRequest): ClientRequestResource
    {
        $this->authorize('projects.manage');
        $this->assertOpen($clientRequest);

        $uuid = $request->validate(['technical_responsible_id' => ['required', 'uuid']])['technical_responsible_id'];
        $rt = TechnicalResponsible::query()->where('uuid', $uuid)->where('active', true)->first();
        if (! $rt) {
            throw new DomainException('Responsável técnico inválido ou inativo.', 'responsible_invalid');
        }
        if ($rt->user_id === null) {
            throw new DomainException('Vincule este RT a um usuário do sistema antes de atribuí-lo.', 'responsible_without_user');
        }

        $clientRequest->forceFill([
            'technical_responsible_id' => $rt->id,
            'assigned_at' => now(),
            'status' => $clientRequest->status === ClientRequestStatus::Submitted ? ClientRequestStatus::InReview : $clientRequest->status,
        ]);
        $clientRequest->addMessage('system', 'Sistema', "Responsável técnico atribuído: {$rt->name}.");
        $clientRequest->save();

        return ClientRequestResource::forStaff($clientRequest->load(self::RELATIONS));
    }

    public function message(Request $request, ClientRequest $clientRequest): ClientRequestResource
    {
        $this->authorize('projects.manage');
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'needs_info' => ['sometimes', 'boolean'],
        ]);

        $clientRequest->addMessage('team', $request->user()->name, $data['text']);
        if ($request->boolean('needs_info') && $clientRequest->status->isOpen()) {
            $clientRequest->status = ClientRequestStatus::NeedsInfo;
        }
        $clientRequest->save();

        return ClientRequestResource::forStaff($clientRequest->load(self::RELATIONS));
    }

    public function cancel(Request $request, ClientRequest $clientRequest): ClientRequestResource
    {
        $this->authorize('projects.manage');
        $this->assertOpen($clientRequest);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];

        $clientRequest->status = ClientRequestStatus::Cancelled;
        $clientRequest->addMessage('team', $request->user()->name, "Solicitação cancelada: {$reason}");
        $clientRequest->save();

        return ClientRequestResource::forStaff($clientRequest->load(self::RELATIONS));
    }

    /** Usuários de portal de um cliente. */
    public function portalUsers(Client $client): JsonResponse
    {
        $this->authorize('clients.view');

        $users = User::query()->where('client_id', $client->id)->orderBy('name')->get(['uuid', 'name', 'email', 'active', 'last_login_at']);

        return response()->json(['data' => $users->map(fn ($u) => [
            'id' => $u->uuid ?? null,
            'name' => $u->name,
            'email' => $u->email,
            'active' => (bool) $u->active,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
        ])]);
    }

    /** Cria o acesso ao portal para o titular (perfil Cliente, vinculado ao cliente). */
    public function createPortalUser(Request $request, Client $client, CreateUser $action): JsonResponse
    {
        $this->authorize('clients.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
        ]);

        $user = DB::transaction(function () use ($action, $data, $client): User {
            $user = $action->handle([...$data, 'active' => true], [SystemRole::Client->value]);
            $user->forceFill(['client_id' => $client->id])->save();

            return $user;
        });

        return response()->json(['data' => ['name' => $user->name, 'email' => $user->email]], 201);
    }

    private function assertOpen(ClientRequest $clientRequest): void
    {
        if (! $clientRequest->status->isOpen()) {
            throw new DomainException('Esta solicitação já foi encerrada.', 'request_closed');
        }
    }
}
