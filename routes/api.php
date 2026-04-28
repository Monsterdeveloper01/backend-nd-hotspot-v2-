<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VoucherPlanController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\RadiusClientController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::get('/router-status', function (App\Services\MikrotikService $mikrotik) {
    return response()->json(['connected' => $mikrotik->connect()]);
});

// Public routes for Landing Page & Checkout
Route::get('/voucher-plans', [VoucherPlanController::class, 'index']);
Route::post('/checkout', [TransactionController::class, 'checkout']);
Route::get('/transaction-status/{id}', [TransactionController::class, 'status']);
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
    Route::post('/radius-clients', [RadiusClientController::class, 'store']);
    Route::delete('/radius-clients/{id}', [RadiusClientController::class, 'destroy']);

    // Admin: Customer Management
    Route::apiResource('customers', CustomerController::class);
    Route::post('customers/{id}/pay-manual', [CustomerController::class, 'payManual']);

    // Admin: Dashboard Stats
    Route::get('/dashboard/stats', [\App\Http\Controllers\Api\DashboardController::class, 'index']);
    Route::get('/dashboard/transactions', [\App\Http\Controllers\Api\DashboardController::class, 'transactions']);
    Route::post('/dashboard/refresh-mikrotik', [\App\Http\Controllers\Api\DashboardController::class, 'refreshMikrotik']);

    // Admin: Network Center (OLT/ONU)
    Route::get('/network/olts', [\App\Http\Controllers\Api\NetworkCenterController::class, 'index']);
    Route::post('/network/olts', [\App\Http\Controllers\Api\NetworkCenterController::class, 'storeOlt']);
    Route::get('/network/olts/{id}/nodes', [\App\Http\Controllers\Api\NetworkCenterController::class, 'nodes']);
    Route::post('/network/olts/{id}/sync', [\App\Http\Controllers\Api\NetworkCenterController::class, 'sync']);
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

// Public Speedtest Endpoints
Route::get('/speedtest/ping', [\App\Http\Controllers\Api\SpeedTestController::class, 'ping']);
Route::get('/speedtest/download', [\App\Http\Controllers\Api\SpeedTestController::class, 'download']);
Route::post('/speedtest/upload', [\App\Http\Controllers\Api\SpeedTestController::class, 'upload']);

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
