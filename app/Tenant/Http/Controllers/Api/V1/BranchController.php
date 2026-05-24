<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branches = DB::connection('tenant')
            ->table('branches')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'phone', 'email', 'address', 'is_active', 'created_at']);
        // ->get(['id', 'name', 'code', 'phone', 'email', 'address', 'manager_name', 'is_active', 'created_at']);

        $response = ['data' => $branches];

        if ($request->boolean('include_default')) {
            $defaultBranchId = null;

            // Get active branch context from settings or session
            $settings = DB::connection('tenant')
                ->table('tenant_settings')
                ->where('key', 'active_branch_id')
                ->first();

            if ($settings && ! empty($settings->value)) {
                $defaultBranchId = (int) $settings->value;
            }

            $response['default_branch_id'] = $defaultBranchId;
        }

        return response()->json($response);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:tenant.branches,code'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $extra = [
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::connection('tenant')->hasColumn('branches', 'system_type')) {
            $extra['system_type'] = 'user_created';
        }

        $id = DB::connection('tenant')->table('branches')->insertGetId(array_merge($validated, $extra));

        $branch = DB::connection('tenant')->table('branches')->find($id);

        return response()->json(['message' => 'Branch created successfully.', 'data' => $branch], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $branch = DB::connection('tenant')->table('branches')->whereNull('deleted_at')->find($id);

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', "unique:tenant.branches,code,{$id}"],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::connection('tenant')->table('branches')->where('id', $id)->update(array_merge($validated, [
            'updated_at' => now(),
        ]));

        $branch = DB::connection('tenant')->table('branches')->find($id);

        return response()->json(['message' => 'Branch updated successfully.', 'data' => $branch]);
    }

    public function destroy(int $id): JsonResponse
    {
        $branch = DB::connection('tenant')->table('branches')->whereNull('deleted_at')->find($id);

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        if ($branch->system_type ?? null === 'system') {
            return response()->json(['message' => 'System branches cannot be deleted.'], 403);
        }

        DB::connection('tenant')->table('branches')->where('id', $id)->update(['deleted_at' => now()]);

        return response()->json(['message' => 'Branch deleted successfully.']);
    }

    public function toggleActive(int $id): JsonResponse
    {
        $branch = DB::connection('tenant')->table('branches')->whereNull('deleted_at')->find($id);

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $newStatus = ! $branch->is_active;

        DB::connection('tenant')->table('branches')->where('id', $id)->update([
            'is_active' => $newStatus,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Branch status updated.', 'is_active' => $newStatus]);
    }
}
