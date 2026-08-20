<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveResource;
use App\Models\Leave;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(Leave::statuses())],
            'user_id' => ['nullable', 'uuid', Rule::exists('mysql_auth.users', 'uuid')],
            'type' => ['nullable', 'string'],
        ]);

        $query = Leave::query()->with(['tenant', 'user', 'approver']);

        // Filter by active tenant if set by middleware
        if ($activeTenantId = $request->attributes->get('current_tenant_id')) {
            $query->where('tenant_id', $activeTenantId);
        } else {
            $tenantIds = $this->resolveAccessibleTenantIds($user);
            if (! empty($tenantIds)) {
                $query->whereIn('tenant_id', $tenantIds);
            }
        }

        // Ownership filter: non-admin / non-owner only see their own records.
        // Kept outside the tenant branch so it always applies when a tenant
        // header is present (production frontend always sends X-Active-Tenant).
        $isOwner = $user->tenant && $user->tenant->owner_id === $userKey;

        if (! $user->hasAnyRole(['admin', 'super-admin']) && !$isOwner) {
            $query->where('user_id', $userKey);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $leaves = $query->latest('created_at')->paginate(20);

        return response()->json([
            'success' => true,
            'leaves' => LeaveResource::collection($leaves)->resolve(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $actorKey = $this->userKey($actor);

        $rules = [
            'type' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string'],
        ];
        $isOwner = $actor->tenant && $actor->tenant->owner_id === $actorKey;
        if ($actor->hasAnyRole(['admin', 'super-admin']) || $isOwner) {
            $rules['user_id'] = ['required', 'uuid', Rule::exists('mysql_auth.users', 'uuid')];
        }
        $request->validate($rules);

        if (! $actor->hasAnyRole(['admin', 'super-admin', 'owner'])) {
            $targetUser = $actor;
        } else {
            $targetUser = User::where('uuid', $request->input('user_id'))->firstOrFail();
            $tenantIds = $this->resolveAccessibleTenantIds($actor);
            if (! in_array($targetUser->tenant_id, $tenantIds, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'User does not belong to accessible tenants.',
                ], 403);
            }
        }

        $start = CarbonImmutable::parse($request->input('start_date'));
        $end = CarbonImmutable::parse($request->input('end_date'));
        $duration = 0;
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            if ($date->isWeekday()) {
                $duration++;
            }
        }
        $duration = max(1, $duration);

        $leave = Leave::create([
            'tenant_id' => $targetUser->tenant_id,
            'user_id' => $this->userKey($targetUser),
            'type' => $request->input('type'),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'duration' => $duration,
            'reason' => $request->input('reason'),
            'status' => Leave::STATUS_PENDING,
        ]);

        $leave->load(['tenant', 'user', 'approver']);

        return response()->json([
            'success' => true,
            'message' => 'Leave request created successfully.',
            'leave' => (new LeaveResource($leave))->resolve(),
        ], 201);
    }

    public function show(Request $request, Leave $leave): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);

        if (! $user->hasAnyRole(['admin', 'super-admin', 'owner'])) {
            if ($leave->user_id !== $userKey) {
                abort(404);
            }
        } else {
            $tenantIds = $this->resolveAccessibleTenantIds($user);
            if (! in_array($leave->tenant_id, $tenantIds, true)) {
                abort(404);
            }
        }

        $leave->load(['tenant', 'user', 'approver']);

        return response()->json([
            'success' => true,
            'leave' => (new LeaveResource($leave))->resolve(),
        ]);
    }

    public function update(Request $request, Leave $leave): JsonResponse
    {
        $actor = $request->user();

        $isOwner = $actor->tenant && $actor->tenant->owner_id === $this->userKey($actor);
        if ($leave->status !== Leave::STATUS_PENDING && ! $actor->hasAnyRole(['admin', 'super-admin']) && !$isOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending leaves can be updated by requester.',
            ], 422);
        }

        $request->validate([
            'type' => ['sometimes', 'required', 'string', 'max:100'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string'],
        ]);

        $data = $request->only(['type', 'start_date', 'end_date', 'reason']);

        if (array_key_exists('start_date', $data) || array_key_exists('end_date', $data)) {
            $start = CarbonImmutable::parse($data['start_date'] ?? $leave->start_date);
            $end = CarbonImmutable::parse($data['end_date'] ?? $leave->end_date);
            $duration = 0;
            for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                if ($date->isWeekday()) {
                    $duration++;
                }
            }
            $data['duration'] = max(1, $duration);
        }

        $leave->update($data);
        $leave->load(['tenant', 'user', 'approver']);

        return response()->json([
            'success' => true,
            'message' => 'Leave request updated successfully.',
            'leave' => (new LeaveResource($leave))->resolve(),
        ]);
    }

    public function destroy(Request $request, Leave $leave): JsonResponse
    {
        $actor = $request->user();
        // Only admins can delete leaves
        if (! $actor->hasAnyRole(['admin', 'super-admin', 'owner'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can delete leave requests.',
            ], 403);
        }

        // Admin must have access to the tenant
        $tenantIds = $this->resolveAccessibleTenantIds($actor);
        if (! in_array($leave->tenant_id, $tenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to remove leave from other tenants.',
            ], 403);
        }

        $leave->delete();

        return response()->json([
            'success' => true,
            'message' => 'Leave request deleted successfully.',
            'leave' => (new LeaveResource($leave))->resolve(),
        ]);
    }

    public function updateStatus(Request $request, Leave $leave): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);

        // 1. Allow Super Admin (Global override)
        if ($user->hasRole('super-admin')) {
             // Authorized
        } 
        // 2. Allow Tenant Owner (Direct DB ownership check)
        elseif ($leave->tenant && $leave->tenant->owner_id === $userKey) {
             // Authorized
        }
        // 3. Allow 'owner' role in the specific tenant context
        else {
            $user->setPermissionsTeamId($leave->tenant_id);
            if (! $user->hasRole('owner')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorised to approve leave requests. Only Owner can perform this action.',
                ], 403);
            }
        }

        $request->validate([
            'status' => ['required', Rule::in(Leave::statuses())],
            'review_notes' => ['nullable', 'string'],
        ]);

        $status = $request->input('status');

        $leave->forceFill([
            'status' => $status,
            'approved_by_id' => $userKey,
            'approved_at' => $status === Leave::STATUS_APPROVED ? now() : null,
            'rejected_at' => $status === Leave::STATUS_REJECTED ? now() : null,
            'review_notes' => $request->input('review_notes'),
        ])->save();

        $leave->load(['tenant', 'user', 'approver']);

        return response()->json([
            'success' => true,
            'message' => 'Leave status updated successfully.',
            'leave' => (new LeaveResource($leave))->resolve(),
        ]);
    }

    private function userKey(User $user): string
    {
        return (string) ($user->uuid ?: $user->id);
    }

    private function resolveAccessibleTenantIds(User $user): array
    {
        $ids = [];

        if ($user->tenant_id) {
            $ids[] = $user->tenant_id;
        }

        if ($user->ownedTenant) {
            $ids[] = $user->ownedTenant->id;
        }

        foreach ($user->tenants as $tenant) {
            $ids[] = $tenant->id;
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
