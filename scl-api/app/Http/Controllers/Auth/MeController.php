<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Services\ReceiptSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    public function __construct(private readonly ReceiptSettingsService $receiptSettings) {}
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user()->load(['branch', 'manager']))]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:191'],
            'phone'  => ['nullable', 'string', 'max:30'],
            'avatar' => ['nullable', 'string', 'max:500'],
        ]);

        $user->update($data);

        return response()->json(['data' => new UserResource($user->fresh(['branch', 'manager']))]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! $user->password || ! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => $request->input('password')]);

        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function getReceiptSettings(Request $request): JsonResponse
    {
        $branchId = $this->receiptBranchId($request);

        return response()->json([
            'data' => array_merge(
                ['branch_id' => $branchId],
                $this->receiptSettings->forBranch($branchId)
            ),
        ]);
    }

    public function updateReceiptSettings(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Only managers can update receipt settings.'], 403);
        }

        $data = $request->validate([
            'branch_id'     => ['required', 'integer'],
            'company_name'  => ['nullable', 'string', 'max:191'],
            'tagline'       => ['nullable', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city'          => ['nullable', 'string', 'max:100'],
            'state'         => ['nullable', 'string', 'max:100'],
            'pincode'       => ['nullable', 'string', 'max:20'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'email'         => ['nullable', 'string', 'max:191'],
            'website'       => ['nullable', 'string', 'max:255'],
            'gstin'         => ['nullable', 'string', 'max:50'],
            'pan'           => ['nullable', 'string', 'max:50'],
            'footer_note'   => ['nullable', 'string', 'max:500'],
            'logo'          => ['nullable', 'string'],
        ]);

        $branchId = $this->receiptBranchId($request);
        $stored = $this->receiptSettings->update($branchId, $data);

        return response()->json([
            'data' => array_merge(['branch_id' => $branchId], $stored),
        ]);
    }

    private function receiptBranchId(Request $request): int
    {
        $user = $request->user();
        $requested = (int) $request->input('branch_id');

        if ($user->isOwner()) {
            $id = $requested;
        } else {
            $id = (int) $user->branch_id;
            if ($requested > 0 && $requested !== $id) {
                abort(403, 'You can only use receipt details for your own branch.');
            }
        }

        if ($id < 1 || ! Branch::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages([
                'branch_id' => ['Select a branch.'],
            ]);
        }

        return $id;
    }
}
