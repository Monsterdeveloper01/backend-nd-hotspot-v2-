<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VoucherPlanController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\RadiusClientController;
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
});

// Public: System Config & Tracking
Route::get('/maintenance/status', [\App\Http\Controllers\Api\SystemConfigController::class, 'getStatus']);
Route::post('/maintenance/bypass', [\App\Http\Controllers\Api\SystemConfigController::class, 'verifyBypass']);
Route::post('/log-visit', [\App\Http\Controllers\Api\AnalyticsController::class, 'logVisit']);

// Public Customer Bill Lookup
Route::get('/search-bill', [\App\Http\Controllers\Api\CustomerController::class, 'searchBill']);
Route::get('/check-voucher', [VoucherController::class, 'checkVoucher']);
Route::get('/customers/{id}/snap-token', [\App\Http\Controllers\Api\CustomerController::class, 'getSnapToken']);

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
