<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * GET /api/roles — list every dynamic role and its assigned permissions.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            Role::with('permissions:id,name,guard_name')->orderBy('name')->get()
        );
    }

    /**
     * POST /api/roles — create a new dynamic role with a set of permissions.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role = Role::create(['name' => $data['name']]);
        $role->syncPermissions($data['permissions']);

        return response()->json($role->load('permissions:id,name,guard_name'), 201);
    }

    /**
     * PUT /api/roles/{id} — rename a role and/or replace its permission set.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if ($role->name === 'super_admin') {
            return response()->json([
                'message' => 'The Super Admin role cannot be modified.',
            ], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }

        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        return response()->json($role->load('permissions:id,name,guard_name'));
    }
}
