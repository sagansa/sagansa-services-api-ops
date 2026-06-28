<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\StoreGroupController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\PosShiftController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\ShiftStoreController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PrinterController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\RoleController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned the "api" middleware group. Make something great!
|
*/

// Preflight OPTIONS fallback for CORS with explicit headers
Route::options('/{any}', function (Request $request) {
    $origin = $request->headers->get('Origin', '*');
    $requestHeaders = $request->headers->get('Access-Control-Request-Headers', '*');
    return response()->noContent(204)
        ->header('Access-Control-Allow-Origin', $origin)
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', $requestHeaders)
        ->header('Access-Control-Allow-Credentials', 'true');
})->where('any', '.*');

// Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [\App\Http\Controllers\Api\Auth\PasswordResetLinkController::class, 'store']);
    Route::post('reset-password', [\App\Http\Controllers\Api\Auth\NewPasswordController::class, 'store']);
    Route::get('verify-email/{id}/{hash}', [\App\Http\Controllers\Api\Auth\VerifyEmailController::class, '__invoke'])
        ->middleware(['signed', 'throttle:6,1']);
    Route::get('invitations/{token}', [AuthController::class, 'showInvitation']);
    Route::post('invitations/{token}', [AuthController::class, 'completeInvitation']);
    
    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);
        Route::get('validate-token', [AuthController::class, 'validateToken']);
        Route::post('switch-tenant', [\App\Http\Controllers\Api\SwitchTenantController::class, 'switchTenant']);
        Route::post('email/verification-notification', [\App\Http\Controllers\Api\Auth\EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1');
        // Generate Signed URL for image upload
    Route::get('/upload-url', [\App\Http\Controllers\ImageUploadUrlController::class, 'getUploadUrl']);
});
});

// Other API routes
Route::middleware(['auth:sanctum'])->group(function () {
    // Stores
    Route::get('/stores', [StoreController::class, 'index']);
    Route::get('/stores/{storeId}', [StoreController::class, 'show']);
    Route::post('/stores', [StoreController::class, 'store']);
    Route::put('/stores/{storeId}', [StoreController::class, 'update']);
    Route::delete('/stores/{storeId}', [StoreController::class, 'destroy']);
    Route::get('/tenants/{tenantId}/stores', [StoreController::class, 'indexByTenant']);
    Route::post('/tenants/{tenantId}/stores', [StoreController::class, 'storeByTenant']);
    Route::put('/tenants/{tenantId}/stores/{storeId}', [StoreController::class, 'updateByTenant']);
    Route::delete('/tenants/{tenantId}/stores/{storeId}', [StoreController::class, 'destroyByTenant']);
    Route::get('/tenants/{tenantId}/store-groups', [StoreGroupController::class, 'index']);
    Route::post('/tenants/{tenantId}/store-groups', [StoreGroupController::class, 'store']);
    Route::put('/tenants/{tenantId}/store-groups/{groupId}', [StoreGroupController::class, 'update']);
    Route::delete('/tenants/{tenantId}/store-groups/{groupId}', [StoreGroupController::class, 'destroy']);
    Route::post('/tenants/{tenantId}/store-groups/{groupId}/sync-settings', [StoreGroupController::class, 'syncSettings']);
    
    // Payment Methods
    Route::get('/payment-methods', [\App\Http\Controllers\Api\PaymentMethodController::class, 'index']);
    Route::post('/payment-methods', [\App\Http\Controllers\Api\PaymentMethodController::class, 'store']);
    Route::get('/payment-methods/{id}/qris', [\App\Http\Controllers\Api\PaymentMethodController::class, 'qris']);
    Route::get('/payment-methods/{id}', [\App\Http\Controllers\Api\PaymentMethodController::class, 'show']);
    Route::put('/payment-methods/{id}', [\App\Http\Controllers\Api\PaymentMethodController::class, 'update']);
    Route::delete('/payment-methods/{id}', [\App\Http\Controllers\Api\PaymentMethodController::class, 'destroy']);
    
    // Categories
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
    
    // Products
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{productId}', [ProductController::class, 'show']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::get('/product-prices', [\App\Http\Controllers\Api\ProductPriceController::class, 'index']);
    Route::post('/product-prices', [\App\Http\Controllers\Api\ProductPriceController::class, 'store']);
    Route::post('/product-prices/bulk', [\App\Http\Controllers\Api\ProductPriceController::class, 'bulk']);
    Route::put('/product-prices/{productPrice}', [\App\Http\Controllers\Api\ProductPriceController::class, 'update']);
    Route::delete('/product-prices/{productPrice}', [\App\Http\Controllers\Api\ProductPriceController::class, 'destroy']);
    
    // Refunds — manager/owner only (role check + active tenant context for owner fallback)
    // Registered BEFORE /orders/{orderId} so the nested refund routes are not
    // swallowed by the wildcard order route.
    Route::middleware(['active.tenant', 'role:manager|owner'])->group(function () {
        Route::get('/refunds', [RefundController::class, 'index']);
        Route::get('/refunds/{refundId}', [RefundController::class, 'show']);
        Route::get('/orders/{orderId}/refund-eligibility', [RefundController::class, 'checkEligibility']);
        Route::post('/orders/{orderId}/refund', [RefundController::class, 'store']);
    });

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/summary', [OrderController::class, 'summary']);
    Route::get('/orders/chart', [OrderController::class, 'chart']);
    Route::get('/orders/{orderId}', [OrderController::class, 'show']);
    Route::put('/orders/{orderId}', [OrderController::class, 'update']);

    // POS shift stock control monitoring
    Route::get('/ops/shifts', [PosShiftController::class, 'index']);
    Route::get('/ops/shifts/reports/stock-variance', [PosShiftController::class, 'stockVarianceReport']);
    Route::get('/ops/shifts/{shift}', [PosShiftController::class, 'show']);
    Route::post('/ops/shifts/{shift}/force-close', [PosShiftController::class, 'forceClose']);
    
    // Presence (Attendance)
    Route::get('/presence', [PresenceController::class, 'index']);
    Route::get('/presence/active', [PresenceController::class, 'active']);
    Route::post('/presence/check-in', [PresenceController::class, 'checkIn']);
    Route::post('/presence/check-out', [PresenceController::class, 'checkOut']);
    Route::get('/presence/{attendanceId}', [PresenceController::class, 'show']);
    
    // Attendance (for admin-web-next compatibility)
    Route::get('/attendance', [AttendanceController::class, 'indexCompat']);
    Route::post('/attendance/check-in', [AttendanceController::class, 'checkInCompat']);
    Route::post('/attendance/checkin', [AttendanceController::class, 'checkInCompat']); // Mobile app compatibility
    Route::post('/attendance/check-out', [AttendanceController::class, 'checkOutCompat']);
    Route::post('/attendance/checkout', [AttendanceController::class, 'checkOutCompat']); // Mobile app compatibility
    Route::patch('/attendance/{attendance}/status', [AttendanceController::class, 'updateStatus']);
    
    // Shift Stores
    Route::get('/tenants/{tenantId}/shift-stores', [ShiftStoreController::class, 'indexByTenant']);
    Route::post('/tenants/{tenantId}/shift-stores', [ShiftStoreController::class, 'storeByTenant']);
    Route::put('/tenants/{tenantId}/shift-stores/{shiftStoreId}', [ShiftStoreController::class, 'updateByTenant']);
    Route::delete('/tenants/{tenantId}/shift-stores/{shiftStoreId}', [ShiftStoreController::class, 'destroyByTenant']);
    
    // Printers
    Route::get('/printers', [PrinterController::class, 'index']);
    Route::get('/printers/{printerId}', [PrinterController::class, 'show']);
    Route::post('/printers', [PrinterController::class, 'store']);
    Route::put('/printers/{printerId}', [PrinterController::class, 'update']);
    Route::post('/printers/{printerId}/test', [PrinterController::class, 'test']);
    Route::get('/printers/{printerId}/jobs/{jobId}', [PrinterController::class, 'getJobStatus']);

    // Leaves
    Route::get('/leaves', [LeaveController::class, 'index']);
    Route::post('/leaves', [LeaveController::class, 'store']);
    Route::get('/leaves/{leave}', [LeaveController::class, 'show']);
    Route::put('/leaves/{leave}', [LeaveController::class, 'update']);
    Route::delete('/leaves/{leave}', [LeaveController::class, 'destroy']);
    Route::patch('/leaves/{leave}/status', [LeaveController::class, 'updateStatus']);
    
    // Users
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{userId}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{userId}', [UserController::class, 'update']);
    Route::delete('/users/{userId}', [UserController::class, 'destroy']);
    Route::patch('/users/{id}/toggle-status', [UserController::class, 'toggleStatus']);

    // Tenants
    Route::get('/tenants', [\App\Http\Controllers\Api\TenantController::class, 'index']);
    Route::get('/tenants/accessible', [\App\Http\Controllers\Api\TenantController::class, 'accessible']);
    Route::post('/tenants', [\App\Http\Controllers\Api\TenantController::class, 'store']);
    Route::get('/tenants/{id}', [\App\Http\Controllers\Api\TenantController::class, 'show']);
    Route::put('/tenants/{id}', [\App\Http\Controllers\Api\TenantController::class, 'update']);
    Route::delete('/tenants/{id}', [\App\Http\Controllers\Api\TenantController::class, 'destroy']);

    // Tables
    Route::get('/tables', [\App\Http\Controllers\Api\TableController::class, 'index']);
    Route::post('/tables', [\App\Http\Controllers\Api\TableController::class, 'store']);
    Route::get('/tables/{id}', [\App\Http\Controllers\Api\TableController::class, 'show']);
    Route::put('/tables/{id}', [\App\Http\Controllers\Api\TableController::class, 'update']);
    Route::delete('/tables/{id}', [\App\Http\Controllers\Api\TableController::class, 'destroy']);

    // Customer Types
    Route::get('/customer-types', [\App\Http\Controllers\Api\CustomerTypeController::class, 'index']);
    Route::post('/customer-types', [\App\Http\Controllers\Api\CustomerTypeController::class, 'store']);
    Route::get('/customer-types/{id}', [\App\Http\Controllers\Api\CustomerTypeController::class, 'show']);
    Route::put('/customer-types/{id}', [\App\Http\Controllers\Api\CustomerTypeController::class, 'update']);
    Route::delete('/customer-types/{id}', [\App\Http\Controllers\Api\CustomerTypeController::class, 'destroy']);

    // Customers
    Route::get('/customers', [\App\Http\Controllers\Api\CustomerController::class, 'index']);
    Route::post('/customers', [\App\Http\Controllers\Api\CustomerController::class, 'store']);
    Route::get('/customers/{id}', [\App\Http\Controllers\Api\CustomerController::class, 'show']);
    Route::put('/customers/{id}', [\App\Http\Controllers\Api\CustomerController::class, 'update']);
    Route::delete('/customers/{id}', [\App\Http\Controllers\Api\CustomerController::class, 'destroy']);

    // Permission System
    Route::get('/permissions', [\App\Http\Controllers\Api\PermissionController::class, 'index']);
    Route::get('/permissions/grouped', [\App\Http\Controllers\Api\TenantUserController::class, 'getPermissions']);
    Route::post('/permissions', [\App\Http\Controllers\Api\PermissionController::class, 'store']);
    Route::put('/permissions/{id}', [\App\Http\Controllers\Api\PermissionController::class, 'update']);
    Route::delete('/permissions/{id}', [\App\Http\Controllers\Api\PermissionController::class, 'destroy']);
    Route::get('/roles', [\App\Http\Controllers\Api\TenantUserController::class, 'getRoles']);

    // Role Management
    Route::post('/roles', [RoleController::class, 'store']);
    Route::get('/roles/{id}', [RoleController::class, 'show']);
    Route::put('/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/roles/{id}', [RoleController::class, 'destroy']);
    Route::put('/roles/{id}/permissions', [RoleController::class, 'syncPermissions']);

    // Tenant-specific user management (multi-tenant pivot)
    Route::middleware(\App\Http\Middleware\SetTenantContext::class)->prefix('tenants/{tenant_id}')->group(function () {
        Route::get('/users', [\App\Http\Controllers\Api\TenantUserController::class, 'index']);
        Route::get('/users/search', [\App\Http\Controllers\Api\TenantUserController::class, 'searchAvailableUsers']);
        Route::post('/users', [\App\Http\Controllers\Api\TenantUserController::class, 'addUser']);
        Route::put('/users/{user_id}/role', [\App\Http\Controllers\Api\TenantUserController::class, 'updateRole']);
        Route::put('/users/{user_id}/permissions', [\App\Http\Controllers\Api\TenantUserController::class, 'assignPermissions']);
        Route::delete('/users/{user_id}', [\App\Http\Controllers\Api\TenantUserController::class, 'removeUser']);
    });

    // ==========================================================================
    // SUBSCRIPTION & BILLING (PRD-SUBSCRIPTION.md)
    // ==========================================================================

    // User-facing billing (auth:sanctum, scope tenant aktif)
    Route::prefix('billing')->group(function () {
        Route::get('/subscription', [\App\Http\Controllers\Api\BillingController::class, 'subscription']);
        Route::get('/dashboard', [\App\Http\Controllers\Api\BillingController::class, 'dashboard']);
        Route::get('/cycles', [\App\Http\Controllers\Api\BillingController::class, 'cycles']);
        Route::get('/cycles/current', [\App\Http\Controllers\Api\BillingController::class, 'currentCycle']);
        Route::get('/cycles/{id}', [\App\Http\Controllers\Api\BillingController::class, 'showCycle']);
        Route::get('/preview', [\App\Http\Controllers\Api\BillingController::class, 'preview']);
        Route::post('/cycles/{id}/pay', [\App\Http\Controllers\Api\BillingController::class, 'pay']);
    });

    // Super-admin billing config (role:super-admin)
    Route::middleware(['role:super-admin'])->prefix('billing/admin')->group(function () {
        Route::get('/settings', [\App\Http\Controllers\Api\BillingAdminController::class, 'getSettings']);
        Route::put('/settings', [\App\Http\Controllers\Api\BillingAdminController::class, 'updateSettings']);
        Route::get('/plans', [\App\Http\Controllers\Api\BillingAdminController::class, 'getPlans']);
        Route::put('/plans/{id}', [\App\Http\Controllers\Api\BillingAdminController::class, 'updatePlan']);
        Route::get('/discounts', [\App\Http\Controllers\Api\BillingAdminController::class, 'getDiscounts']);
        Route::post('/discounts', [\App\Http\Controllers\Api\BillingAdminController::class, 'createDiscount']);
        Route::put('/discounts/{id}', [\App\Http\Controllers\Api\BillingAdminController::class, 'updateDiscount']);
        Route::delete('/discounts/{id}', [\App\Http\Controllers\Api\BillingAdminController::class, 'deleteDiscount']);
        Route::get('/tenants', [\App\Http\Controllers\Api\BillingAdminController::class, 'getTenants']);
        Route::put('/tenants/{tenantId}/exempt', [\App\Http\Controllers\Api\BillingAdminController::class, 'setExemption']);
        Route::get('/billing-overview', [\App\Http\Controllers\Api\BillingAdminController::class, 'billingOverview']);
    });
});

// Billing webhooks (PUBLIK tapi terverifikasi signature/token)
Route::prefix('webhooks')->group(function () {
    Route::post('/xendit', [\App\Http\Controllers\Api\BillingWebhookController::class, 'handleXendit']);
    Route::post('/midtrans', [\App\Http\Controllers\Api\BillingWebhookController::class, 'handleMidtrans']);
});

// Other API routes would go here...
