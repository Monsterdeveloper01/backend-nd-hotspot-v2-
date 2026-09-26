<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VoucherPlanController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\RadiusClientController;
use App\Http\Controllers\Api\WhatsAppBotController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\PointAdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', function() { return view('welcome'); });
Route::post('/login', [AuthController::class, 'login']);
Route::get('/router-status', function (App\Services\MikrotikService $mikrotik) {
    return response()->json(['connected' => $mikrotik->connect()]);
});

// Public routes for Landing Page & Checkout
Route::get('/voucher-plans', [VoucherPlanController::class, 'index']);
Route::post('/checkout', [TransactionController::class, 'checkout']);
Route::get('/transaction-status/{id}', [TransactionController::class, 'status']);
Route::get('/transactions/{id}', [TransactionController::class, 'status']); 
Route::get('/voucher/details', [TransactionController::class, 'getVoucherByOrder']); // Tambahkan ini untuk polling voucher
Route::post('/midtrans-callback', [TransactionController::class, 'callback']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Admin: Master Voucher Management
    Route::post('/voucher-plans', [VoucherPlanController::class, 'store']);
    Route::put('/voucher-plans/{id}', [VoucherPlanController::class, 'update']);
    Route::delete('/voucher-plans/{id}', [VoucherPlanController::class, 'destroy']);

    // Admin: Voucher Management & Tracking
    Route::get('/vouchers', [VoucherController::class, 'index']);
    Route::get('/active-vouchers', [VoucherController::class, 'activeVouchers']);
    Route::get('/sold-vouchers', [VoucherController::class, 'soldVouchers']);
    Route::get('/voucher-logs', [VoucherController::class, 'getLogs']);
    Route::post('/vouchers/generate', [VoucherController::class, 'generate']);
    Route::post('/vouchers/{code}/kick', [VoucherController::class, 'kickUser']);
    Route::delete('/vouchers/{id}', [VoucherController::class, 'destroy']);

    // Admin: RADIUS Management
    Route::get('/radius-clients', [RadiusClientController::class, 'index']);
    Route::get('/radius-logs', [RadiusClientController::class, 'getLogs']);
    Route::get('/radius-status', [RadiusClientController::class, 'status']);
    Route::post('/radius-clients', [RadiusClientController::class, 'store']);
    Route::delete('/radius-clients/{id}', [RadiusClientController::class, 'destroy']);

    // Admin: Customer Management
    Route::apiResource('customers', CustomerController::class);
    Route::post('customers/{id}/pay-manual', [CustomerController::class, 'payManual']);
    Route::post('customers/{id}/toggle-status', [CustomerController::class, 'toggleStatus']);

    // Admin: Dashboard Stats
    Route::get('/dashboard/stats', [\App\Http\Controllers\Api\DashboardController::class, 'index']);
    Route::get('/dashboard/transactions', [\App\Http\Controllers\Api\DashboardController::class, 'transactions']);
    Route::post('/dashboard/refresh-mikrotik', [\App\Http\Controllers\Api\DashboardController::class, 'refreshMikrotik']);

    // Admin: MikroTik Status
    Route::get('/mikrotik/status', function (App\Services\MikrotikService $mikrotik) {
        return response()->json($mikrotik->getStatus());
    });

    // Admin: Network Center (OLT/ONU)
    Route::get('/olt', [\App\Http\Controllers\Api\NetworkCenterController::class, 'getOlt']);
    Route::get('/olt/{id}/onu', [\App\Http\Controllers\Api\NetworkCenterController::class, 'getOnu']);
    Route::get('/olt/{id}/onu/live', [\App\Http\Controllers\Api\NetworkCenterController::class, 'getOnuLive']);
    Route::post('/olt/{id}/sync', [\App\Http\Controllers\Api\NetworkCenterController::class, 'syncOlt']);
    Route::get('/olt/{id}/status', [\App\Http\Controllers\Api\NetworkCenterController::class, 'getStatus']);
    
    Route::post('/network/olts', [\App\Http\Controllers\Api\NetworkCenterController::class, 'storeOlt']);
    Route::put('/network/olts/{id}', [\App\Http\Controllers\Api\NetworkCenterController::class, 'updateOlt']);
    Route::delete('/network/olts/{id}', [\App\Http\Controllers\Api\NetworkCenterController::class, 'deleteOlt']);
    Route::put('/network/nodes/{id}', [\App\Http\Controllers\Api\NetworkCenterController::class, 'updateNode']);
    Route::post('/network/nodes/{id}/reboot', [\App\Http\Controllers\Api\NetworkCenterController::class, 'reboot']);

    // Admin: Analytics
    Route::get('/analytics/peak-hours', [\App\Http\Controllers\Api\AnalyticsController::class, 'getPeakHours']);

    // Admin: System Config
    Route::post('/maintenance/toggle', [\App\Http\Controllers\Api\SystemConfigController::class, 'toggleMaintenance']);

    // Admin: WhatsApp Bot Complaints
    Route::get('/admin/complaints', [WhatsAppBotController::class, 'getComplaints']);
    Route::patch('/admin/complaints/{id}/read', [WhatsAppBotController::class, 'markAsRead']);

    // Admin: Event & Loyalty Analytics (Phase 1 — Permanent Real-Time)
    Route::get('/admin/events', [EventController::class, 'index']);
    Route::post('/admin/events', [EventController::class, 'store']);
    Route::put('/admin/events/{id}', [EventController::class, 'update']);
    Route::delete('/admin/events/{id}', [EventController::class, 'destroy']);
    Route::post('/admin/events/{id}/sync', [EventController::class, 'sync']);
    Route::get('/admin/events/{id}/analytics', [EventController::class, 'analytics']);

    // Admin: Event Reward Rules & Automation (Phase 2 — Automatic Reward System)
    Route::get('/admin/events/{id}/reward-rules', [EventController::class, 'getRewardRules']);
    Route::post('/admin/events/{id}/reward-rules', [EventController::class, 'storeRewardRule']);
    Route::put('/admin/events/{id}/reward-rules/{ruleId}', [EventController::class, 'updateRewardRule']);
    Route::delete('/admin/events/{id}/reward-rules/{ruleId}', [EventController::class, 'deleteRewardRule']);
    Route::post('/admin/events/{id}/reward-rules/{ruleId}/process-eligible', [EventController::class, 'processRetroactiveRewards']);
    Route::post('/admin/events/{id}/rewards/{rewardId}/retry', [EventController::class, 'retryReward']);

    // Admin: Loyalty Test Mode (Simulation & Integration Test)
    Route::get('/admin/events/{id}/loyalty-test/status', [EventController::class, 'getLoyaltyTestStatus']);
    Route::post('/admin/events/{id}/loyalty-test/run', [EventController::class, 'runLoyaltyTest']);
    Route::post('/admin/events/{id}/loyalty-test/reset', [EventController::class, 'resetLoyaltyTest']);

    // Admin: ND-Point System (Silent Tracking Phase — Internal Analytics & Ledger)
    Route::get('/admin/points/analytics', [PointAdminController::class, 'analytics']);
    Route::get('/admin/points/accounts', [PointAdminController::class, 'accounts']);
    Route::get('/admin/points/accounts/{id}', [PointAdminController::class, 'accountDetail']);
    Route::get('/admin/points/rules', [PointAdminController::class, 'rules']);
    Route::post('/admin/points/rules', [PointAdminController::class, 'storeRule']);
    Route::put('/admin/points/rules/{id}', [PointAdminController::class, 'updateRule']);
    Route::delete('/admin/points/rules/{id}', [PointAdminController::class, 'deleteRule']);
    Route::post('/admin/points/adjust', [PointAdminController::class, 'adjustPoints']);
    Route::post('/admin/points/reconcile', [PointAdminController::class, 'reconcile']);
    Route::post('/admin/points/toggle-system', [PointAdminController::class, 'toggleSystem']);
});

// Public: System Config & Tracking
Route::get('/maintenance/status', [\App\Http\Controllers\Api\SystemConfigController::class, 'getStatus']);
Route::post('/maintenance/bypass', [\App\Http\Controllers\Api\SystemConfigController::class, 'verifyBypass']);
Route::post('/log-visit', [\App\Http\Controllers\Api\AnalyticsController::class, 'logVisit']);

// Public Customer Bill Lookup
Route::get('/search-bill', [\App\Http\Controllers\Api\CustomerController::class, 'searchBill']);
Route::get('/check-voucher', [VoucherController::class, 'checkVoucher']);
Route::get('/customers/{id}/snap-token', [\App\Http\Controllers\Api\CustomerController::class, 'getSnapToken']);

// Public Loyalty Progress Check & Active Event Info
Route::match(['get', 'post'], '/loyalty/progress', [\App\Http\Controllers\Api\PublicLoyaltyController::class, 'check']);
Route::get('/loyalty/active-event', [\App\Http\Controllers\Api\PublicLoyaltyController::class, 'getActiveEvent']);

// WhatsApp Bot API (called by WA Gateway - no auth needed)
Route::get('/wa/check-status', [WhatsAppBotController::class, 'checkStatus']);
Route::post('/wa/complaint', [WhatsAppBotController::class, 'storeComplaint']);

Route::get('/test', function () {
    return response()->json([
        'message' => 'Hello from Laravel API!',
        'status' => 'success'
    ]);
});

// Fallback: Jika akses route API yang tidak ada atau akses lewat browser sembarangan
Route::any('{any}', function (Illuminate\Http\Request $request) {
    if ($request->expectsJson()) {
        return response()->json(['message' => 'Endpoint not found'], 404);
    }
    return view('welcome');
})->where('any', '.*');
