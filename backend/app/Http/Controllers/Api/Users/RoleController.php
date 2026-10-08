<?php

namespace App\Http\Controllers\Api\Users;

use App\Domain\Users\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()->with('permissions')->withCount('users')->orderBy('name')->get();

        return RoleResource::collection($roles);
    }
}
