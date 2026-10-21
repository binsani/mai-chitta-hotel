<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::with('permissions:id,slug')->withCount('users')->orderBy('id')->get()
            ->map(fn (Role $r) => $this->present($r));

        return response()->json(['data' => $roles]);
    }

    public function permissions(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        return response()->json(['data' => Permission::orderBy('module')->orderBy('id')->get(['slug', 'module', 'name'])
            ->groupBy('module')]);
    }

    public function store(SaveRoleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $role = DB::transaction(function () use ($data) {
            $role = Role::create(['slug' => $data['slug'], 'name' => $data['name'], 'description' => $data['description'] ?? null]);
            $role->permissions()->sync(Permission::whereIn('slug', $data['permissions'] ?? [])->pluck('id'));
            $this->audit->log('role.created', $role, ['permissions' => $data['permissions'] ?? []]);

            return $role;
        });

        return response()->json(['data' => $this->present($role->load('permissions')->loadCount('users'))], 201);
    }

    public function update(SaveRoleRequest $request, Role $role): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($role, $data) {
            $role->fill(array_intersect_key($data, array_flip(['name', 'description'])))->save();
            if (array_key_exists('permissions', $data)) {
                $before = $role->permissions()->pluck('slug')->all();
                $role->permissions()->sync(Permission::whereIn('slug', $data['permissions'])->pluck('id'));
                $this->audit->log('role.permissions_changed', $role, [
                    'granted' => array_values(array_diff($data['permissions'], $before)),
                    'revoked' => array_values(array_diff($before, $data['permissions'])),
                ]);
            }
        });

        return response()->json(['data' => $this->present($role->fresh('permissions')->loadCount('users'))]);
    }

    public function destroy(Role $role): JsonResponse
    {
        Gate::authorize('delete', $role);
        $this->audit->log('role.deleted', $role, ['slug' => $role->slug]);
        $role->delete();

        return response()->json(null, 204);
    }

    private function present(Role $r): array
    {
        return [
            'id' => $r->id, 'slug' => $r->slug, 'name' => $r->name, 'description' => $r->description,
            'is_system' => $r->is_system, 'users_count' => $r->users_count ?? null,
            'permissions' => $r->permissions->pluck('slug')->values(),
        ];
    }
}
