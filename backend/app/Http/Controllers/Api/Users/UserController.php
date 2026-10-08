<?php

namespace App\Http\Controllers\Api\Users;

use App\Domain\Users\Actions\CreateUser;
use App\Domain\Users\Actions\UpdateUser;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'nullable', 'boolean'],
            'role' => ['sometimes', 'nullable', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($validated['search'] ?? null, function ($q, string $search): void {
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(email) like ?', [$term]));
            })
            ->when(isset($validated['active']), fn ($q) => $q->where('active', (bool) $validated['active']))
            ->when($validated['role'] ?? null, fn ($q, string $role) => $q->whereHas('roles', fn ($r) => $r->where('slug', $role)))
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request, CreateUser $action): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->safe()->only(['name', 'email', 'password', 'active']);
        $user = $action->handle($data, $request->validated('roles'));

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user->load('roles'));
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $action): UserResource
    {
        $this->authorize('update', $user);

        $data = array_filter(
            $request->safe()->only(['name', 'email', 'password', 'active']),
            fn ($value, $key) => ! ($key === 'password' && ($value === null || $value === '')),
            ARRAY_FILTER_USE_BOTH,
        );

        $updated = $action->handle($request->user(), $user, $data, $request->validated('roles'));

        return new UserResource($updated);
    }
}
