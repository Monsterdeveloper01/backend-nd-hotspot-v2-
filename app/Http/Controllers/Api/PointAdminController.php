<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppConfig;
use App\Models\PointAccount;
use App\Models\PointRule;
use App\Models\PointTransaction;
use App\Services\PhoneNumberService;
use App\Services\PointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PointAdminController extends Controller
{
    /**
     * Get aggregate point analytics.
     */
    public function analytics(): JsonResponse
    {
        $data = PointService::getAnalytics();
        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * List customer point accounts with search.
     */
    public function accounts(Request $request): JsonResponse
    {
        $query = PointAccount::query();

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $normalizedSearch = PhoneNumberService::normalize($search) ?? $search;
            $query->where('phone', 'like', "%{$normalizedSearch}%");
        }

        $sort = $request->input('sort', 'balance');
        $dir = $request->input('dir', 'desc');
        if (in_array($sort, ['balance', 'lifetime_earned', 'lifetime_spent', 'created_at'], true)) {
            $query->orderBy($sort, strtolower($dir) === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('balance', 'desc');
        }

        $accounts = $query->paginate($request->input('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $accounts,
        ]);
    }

    /**
     * Get single account details with full ledger history.
     */
    public function accountDetail($id): JsonResponse
    {
        $account = PointAccount::with(['transactions' => function ($q) {
            $q->orderBy('created_at', 'desc')->limit(100);
        }])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'account' => [
                'id' => $account->id,
                'phone' => $account->phone,
                'masked_phone' => $account->masked_phone,
                'balance' => $account->balance,
                'lifetime_earned' => $account->lifetime_earned,
                'lifetime_spent' => $account->lifetime_spent,
                'status' => $account->status,
                'created_at' => $account->created_at ? $account->created_at->format('Y-m-d H:i:s') : null,
            ],
            'transactions' => $account->transactions,
        ]);
    }

    /**
     * List all point rules.
     */
    public function rules(): JsonResponse
    {
        $rules = PointRule::orderBy('priority', 'asc')->get();
        return response()->json([
            'status' => 'success',
            'data' => $rules,
        ]);
    }

    /**
     * Store new point rule.
     */
    public function storeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|string|in:purchase,event,bonus,multiplier',
            'calculation_type' => 'required|string|in:per_unit,ratio,fixed_bonus,flat,percentage,multiplier',
            'value' => 'required|numeric|min:0.01',
            'unit_amount' => 'nullable|numeric|min:1',
            'min_purchase_amount' => 'nullable|numeric|min:0',
            'max_purchase_amount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ]);

        $rule = PointRule::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Point rule created successfully.',
            'data' => $rule,
        ], 201);
    }

    /**
     * Update existing point rule.
     */
    public function updateRule(Request $request, $id): JsonResponse
    {
        $rule = PointRule::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|required|string|in:purchase,event,bonus,multiplier',
            'calculation_type' => 'sometimes|required|string|in:per_unit,ratio,fixed_bonus,flat,percentage,multiplier',
            'value' => 'sometimes|required|numeric|min:0.01',
            'unit_amount' => 'nullable|numeric|min:1',
            'min_purchase_amount' => 'nullable|numeric|min:0',
            'max_purchase_amount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ]);

        $rule->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Point rule updated successfully.',
            'data' => $rule,
        ]);
    }

    /**
     * Delete point rule.
     */
    public function deleteRule($id): JsonResponse
    {
        $rule = PointRule::findOrFail($id);
        $rule->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Point rule deleted successfully.',
        ]);
    }

    /**
     * Admin manual point adjustment.
     */
    public function adjustPoints(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'points' => 'required|integer',
            'description' => 'required|string|max:255',
        ]);

        try {
            $adminId = Auth::id();
            $ledger = PointService::adjustPoints(
                $validated['phone'],
                (int) $validated['points'],
                $validated['description'],
                $adminId
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Penyesuaian poin berhasil dicatat.',
                'data' => $ledger,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses penyesuaian: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin Safe Reconciliation Tool.
     */
    public function reconcile(Request $request): JsonResponse
    {
        $dryRun = $request->boolean('dry_run', true);
        $period = $request->input('period', 'current_month'); // current_month | last_30_days
        $result = PointService::reconcileMissing($dryRun, $period);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * Toggle master point system enable/disable.
     */
    public function toggleSystem(Request $request): JsonResponse
    {
        $enabled = $request->boolean('enabled', true);
        AppConfig::updateOrCreate(
            ['key' => 'point_system_enabled'],
            ['value' => $enabled ? 'true' : 'false']
        );

        return response()->json([
            'status' => 'success',
            'enabled' => $enabled,
            'message' => $enabled ? 'Sistem ND-Point aktif (Silent Tracking).' : 'Sistem ND-Point dinonaktifkan sementara.',
        ]);
    }
}
