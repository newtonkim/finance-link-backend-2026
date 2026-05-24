<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Exports\GroupMembersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SavingsGroupRequest;
use App\Models\Member;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use App\Tenant\Modules\Groups\Services\SavingsGroupService;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\SavingsAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class SavingsGroupController extends Controller
{
    public function __construct(
        protected SavingsGroupService $service
    ) {}

    public function index(Request $request)
    {
        $query = SavingsGroup::query()->orderBy('created_at', 'desc');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('primary_contact_phone', 'like', "%{$search}%");
            });
        }

        $groups = $query->paginate(15)->withQueryString();

        $groups->getCollection()->transform(function ($group) {
            $group->image_url = $group->image_path
                ? Storage::disk('public')->url($group->image_path)
                : null;

            return $group;
        });

        return response()->json($groups);
    }

    public function store(SavingsGroupRequest $request)
    {
        try {
            $group = $this->service->create($request->validated());

            return response()->json(['message' => 'Savings group created successfully.', 'data' => $group], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create savings group: '.$e->getMessage()], 500);
        }
    }

    public function show(SavingsGroup $savingsGroup)
    {
        $savingsGroup->load('members');

        return response()->json(['data' => $savingsGroup]);
    }

    public function update(SavingsGroupRequest $request, SavingsGroup $savingsGroup)
    {
        try {
            $this->service->update($savingsGroup, $request->validated());

            return response()->json(['message' => 'Savings group updated successfully.', 'data' => $savingsGroup->fresh()]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update savings group: '.$e->getMessage()], 500);
        }
    }

    public function destroy(SavingsGroup $savingsGroup)
    {
        try {
            $this->service->delete($savingsGroup);

            return response()->json(['message' => 'Savings group deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete savings group: '.$e->getMessage()], 500);
        }
    }

    public function members(SavingsGroup $savingsGroup)
    {
        $savingsGroup->load(['members' => function ($query) {
            $query->orderBy('savings_group_members.created_at', 'desc');
        }]);

        $allMembers = Member::select('id', 'name', 'member_number', 'phone')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $savingsGroup,
            'all_members' => $allMembers,
        ]);
    }

    public function addMember(Request $request, SavingsGroup $savingsGroup)
    {
        $validated = $request->validate([
            'member_id' => 'nullable|exists:tenant.members,id',
            'mode' => 'required|in:add_existing,create_new',
            'name' => 'required_if:mode,create_new|nullable|string|max:255',
            'phone' => ['required_if:mode,create_new', 'nullable', 'string', 'max:20', 'regex:/^[0-9+]{9,15}$/'],
            'phone_country' => 'required_if:mode,create_new|nullable|string|max:5',
            'national_id_number' => 'nullable|string|max:50',
            'role' => 'nullable|string|max:50',
        ], [
            'phone.regex' => 'Please provide a valid phone number (9-15 digits, + allowed).',
        ]);

        try {
            return DB::connection('tenant')->transaction(function () use ($validated, $savingsGroup) {
                $memberId = $validated['member_id'] ?? null;

                if ($validated['mode'] === 'create_new') {
                    $existingMember = Member::where('phone', $validated['phone'])->first();

                    if ($existingMember) {
                        $memberId = $existingMember->id;
                    } else {
                        $lastMember = Member::withTrashed()->orderBy('id', 'desc')->first();
                        $nextNumber = $lastMember ? ((int) substr($lastMember->member_number, 4)) + 1 : 1;
                        $memberNumber = 'MBR-'.str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

                        $member = Member::create([
                            'name' => $validated['name'],
                            'phone' => $validated['phone'],
                            'phone_country' => $validated['phone_country'] ?? 'UG',
                            'national_id_number' => $validated['national_id_number'],
                            'member_number' => $memberNumber,
                            'member_type' => Member::TYPE_GROUP_ONLY,
                            'password' => Hash::make(Str::random(12)),
                            'status' => 'active',
                            'joined_at' => now(),
                            'created_by' => auth()->id(),
                        ]);
                        $memberId = $member->id;

                        $defaultProduct = SavingsProduct::where('name', 'General Savings Account')->first();
                        if ($defaultProduct) {
                            app(SavingsAccountService::class)->create([
                                'member_id' => $memberId,
                                'savings_product_id' => $defaultProduct->id,
                                'account_type' => 'voluntary',
                                'is_new_account' => true,
                                'initial_deposit' => 0,
                                'consider_min_balance' => true,
                                'status' => 'active',
                            ]);
                        }
                    }
                }

                if ($savingsGroup->members()->where('member_id', $memberId)->exists()) {
                    return response()->json(['message' => 'Member is already in this group.'], 422);
                }

                $savingsGroup->members()->attach($memberId, [
                    'role' => $validated['role'] ?? 'Member',
                ]);

                return response()->json(['message' => 'Member added to group successfully.']);
            });
        } catch (\Exception $e) {
            Log::error('Failed to add group member: '.$e->getMessage());

            return response()->json(['message' => 'Failed to add member: '.$e->getMessage()], 500);
        }
    }

    public function removeMember(SavingsGroup $savingsGroup, Member $member)
    {
        $savingsGroup->members()->detach($member->id);

        return response()->json(['message' => 'Member removed from group successfully.']);
    }

    public function exportMembers(SavingsGroup $savingsGroup)
    {
        $savingsGroup->load('members');
        $fileName = str_replace(' ', '_', $savingsGroup->name).'_Members.xlsx';

        return Excel::download(new GroupMembersExport($savingsGroup), $fileName);
    }
}
