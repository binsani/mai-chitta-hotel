<?php

use App\Http\Controllers\Api\V1\Admin\AccountingController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\ContactInquiryController;
use App\Http\Controllers\Api\V1\Admin\FolioController;
use App\Http\Controllers\Api\V1\Admin\HousekeepingController;
use App\Http\Controllers\Api\V1\Admin\HumanResourcesController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\MaintenanceController;
use App\Http\Controllers\Api\V1\Admin\MediaController;
use App\Http\Controllers\Api\V1\Admin\MenuController;
use App\Http\Controllers\Api\V1\Admin\OfferController;
use App\Http\Controllers\Api\V1\Admin\PageController;
use App\Http\Controllers\Api\V1\Admin\PaymentController;
use App\Http\Controllers\Api\V1\Admin\PosController;
use App\Http\Controllers\Api\V1\Admin\ProcurementController;
use App\Http\Controllers\Api\V1\Admin\RatePlanController;
use App\Http\Controllers\Api\V1\Admin\ReservationController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\RoomContentController;
use App\Http\Controllers\Api\V1\Admin\RoomController;
use App\Http\Controllers\Api\V1\Admin\RoomTypeController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\TestimonialController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\WebsiteSettingsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PublicBookingController;
use App\Http\Controllers\Api\V1\PublicWebsiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    // Public Website & CMS read-only endpoints (published content only)
    Route::get('settings/public', [SettingsController::class, 'public']);
    Route::get('public/bootstrap', [PublicWebsiteController::class, 'bootstrap']);
    Route::get('pages', [PublicWebsiteController::class, 'pages']);
    Route::get('pages/{slug}', [PublicWebsiteController::class, 'showPage'])->where('slug', '[a-z0-9-]+');
    Route::get('rooms', [PublicWebsiteController::class, 'rooms']);
    Route::get('rooms/{slug}', [PublicWebsiteController::class, 'showRoom'])->where('slug', '[a-z0-9-]+');
    Route::get('offers', [PublicWebsiteController::class, 'offers']);
    Route::get('offers/{slug}', [PublicWebsiteController::class, 'showOffer'])->where('slug', '[a-z0-9-]+');
    Route::get('gallery', [PublicWebsiteController::class, 'gallery']);
    Route::get('testimonials', [PublicWebsiteController::class, 'testimonials']);
    Route::post('contact', [PublicWebsiteController::class, 'submitContact'])->middleware('throttle:contact');

    // Phase 3 Public Online Booking, Availability, Quotes, Holds & Webhooks
    Route::get('public/room-types', [PublicBookingController::class, 'roomTypes']);
    Route::get('public/rate-plans', [PublicBookingController::class, 'ratePlans']);
    Route::get('public/availability', [PublicBookingController::class, 'availability'])->middleware('throttle:booking-quote');
    Route::post('public/booking-quotes', [PublicBookingController::class, 'createQuote'])->middleware('throttle:booking-quote');
    Route::post('public/booking-holds', [PublicBookingController::class, 'createHold'])->middleware('throttle:booking-create');
    Route::post('public/reservations', [PublicBookingController::class, 'createReservation'])->middleware('throttle:booking-create');
    Route::get('public/reservations/{reference}', [PublicBookingController::class, 'showReservation']);
    Route::post('public/reservations/{reference}/cancellation-requests', [PublicBookingController::class, 'requestCancellation'])->middleware('throttle:booking-create');
    Route::post('public/webhooks/payments/{provider}', [PublicBookingController::class, 'webhook']);

    // Authentication
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');

    // Authenticated (Sanctum stateful session cookie)
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/user', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('auth/password', [AuthController::class, 'changePassword']);

        Route::get('dashboard', [SettingsController::class, 'dashboard']);

        Route::prefix('admin')->group(function () {
            Route::get('contact-inquiries', [ContactInquiryController::class, 'index']);
            Route::put('contact-inquiries/{contactInquiry}', [ContactInquiryController::class, 'update']);
            Route::get('employees', [HumanResourcesController::class, 'index']);
            Route::post('employees', [HumanResourcesController::class, 'store']);
            Route::put('employees/{employee}', [HumanResourcesController::class, 'update']);
            Route::get('payroll', [HumanResourcesController::class, 'payroll']);
            Route::get('payroll/export', [HumanResourcesController::class, 'exportPayroll']);
            Route::post('payroll', [HumanResourcesController::class, 'preparePayroll']);
            Route::post('payroll/{payrollEntry}/review', [HumanResourcesController::class, 'reviewPayroll']);
            // System administration
            Route::apiResource('users', UserController::class)->except('destroy');
            Route::get('permissions', [RoleController::class, 'permissions']);
            Route::apiResource('roles', RoleController::class)->except('show');
            Route::get('audit-logs', [AuditLogController::class, 'index']);
            Route::get('settings', [SettingsController::class, 'show']);
            Route::put('settings', [SettingsController::class, 'update']);

            // Website Management & CMS
            Route::apiResource('pages', PageController::class);
            Route::post('pages/{page}/publish', [PageController::class, 'publish']);
            Route::post('pages/{page}/unpublish', [PageController::class, 'unpublish']);
            Route::post('pages/{page}/archive', [PageController::class, 'archive']);
            Route::get('pages/{page}/revisions', [PageController::class, 'revisions']);
            Route::post('pages/{page}/revisions/{revision}/restore', [PageController::class, 'restoreRevision']);

            Route::get('media/{medium}/download', [MediaController::class, 'download']);
            Route::apiResource('media', MediaController::class)->except('show');

            Route::get('menus', [MenuController::class, 'index']);
            Route::put('menus/{slug}', [MenuController::class, 'update'])->where('slug', '[a-z0-9-]+');

            Route::apiResource('room-contents', RoomContentController::class);
            Route::apiResource('offers', OfferController::class);
            Route::apiResource('testimonials', TestimonialController::class)->except('show');

            Route::get('website-settings', [WebsiteSettingsController::class, 'show']);
            Route::put('website-settings', [WebsiteSettingsController::class, 'update']);

            // Phase 3: Rooms, Room Types, Rate Plans, Availability, Reservations, Front Desk & Payments
            Route::apiResource('room-types', RoomTypeController::class);
            Route::apiResource('rooms', RoomController::class)->except('show');

            Route::apiResource('rate-plans', RatePlanController::class);
            Route::post('rate-plans/{rate_plan}/overrides', [RatePlanController::class, 'storeOverride']);
            Route::delete('rate-plans/{rate_plan}/overrides/{override}', [RatePlanController::class, 'destroyOverride']);

            Route::get('availability', [ReservationController::class, 'availabilityMatrix']);
            Route::get('front-desk', [ReservationController::class, 'frontDeskSummary']);

            Route::apiResource('reservations', ReservationController::class)->except('destroy');
            Route::post('reservations/{reservation}/assign-room', [ReservationController::class, 'assignRoom']);
            Route::post('reservations/{reservation}/check-in', [ReservationController::class, 'checkIn']);
            Route::post('reservations/{reservation}/check-out', [ReservationController::class, 'checkOut']);
            Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
            Route::post('reservations/{reservation}/no-show', [ReservationController::class, 'noShow']);
            Route::post('reservations/{reservation}/payments', [PaymentController::class, 'storeManualPayment']);
            Route::post('reservations/{reservation}/refunds', [PaymentController::class, 'requestRefund']);

            Route::get('payments', [PaymentController::class, 'index']);
            Route::post('payments/{paymentTransaction}/reverse', [PaymentController::class, 'reversePayment']);
            Route::get('refunds', [PaymentController::class, 'refunds']);
            Route::post('refunds/{paymentRefund}/review', [PaymentController::class, 'reviewRefund']);

            Route::get('guests', [ReservationController::class, 'guests']);
            Route::put('guests/{guest}', [ReservationController::class, 'updateGuest']);

            // Phase 4: Housekeeping Management, Maintenance Work Orders, Guest Folios & Operational Notifications
            Route::get('housekeeping/tasks', [HousekeepingController::class, 'index']);
            Route::post('housekeeping/tasks', [HousekeepingController::class, 'store']);
            Route::get('housekeeping/tasks/{housekeepingTask}', [HousekeepingController::class, 'show']);
            Route::post('housekeeping/tasks/{housekeepingTask}/assign', [HousekeepingController::class, 'assign']);
            Route::post('housekeeping/tasks/{housekeepingTask}/start', [HousekeepingController::class, 'start']);
            Route::post('housekeeping/tasks/{housekeepingTask}/complete', [HousekeepingController::class, 'complete']);
            Route::post('housekeeping/tasks/{housekeepingTask}/inspect', [HousekeepingController::class, 'inspect']);
            Route::post('housekeeping/tasks/{housekeepingTask}/cancel', [HousekeepingController::class, 'cancel']);

            Route::get('maintenance/work-orders', [MaintenanceController::class, 'index']);
            Route::post('maintenance/work-orders', [MaintenanceController::class, 'store']);
            Route::get('maintenance/work-orders/{maintenanceWorkOrder}', [MaintenanceController::class, 'show']);
            Route::put('maintenance/work-orders/{maintenanceWorkOrder}', [MaintenanceController::class, 'update']);
            Route::post('maintenance/work-orders/{maintenanceWorkOrder}/assign', [MaintenanceController::class, 'assign']);

            Route::get('folios', [FolioController::class, 'index']);
            Route::get('folios/{guestFolio}', [FolioController::class, 'show']);
            Route::get('reservations/{reservation}/folio', [FolioController::class, 'showByReservation']);
            Route::post('folios/{guestFolio}/sync-accommodation', [FolioController::class, 'syncAccommodation']);
            Route::post('folios/{guestFolio}/charges', [FolioController::class, 'postCharge']);
            Route::post('folios/{guestFolio}/adjustments', [FolioController::class, 'postAdjustment']);
            Route::post('folio-entries/{folioEntry}/review', [FolioController::class, 'reviewAdjustment']);
            Route::post('folio-entries/{folioEntry}/reverse', [FolioController::class, 'reverseEntry']);
            Route::post('folios/{guestFolio}/allocate-payment', [FolioController::class, 'allocatePayment']);
            Route::post('folios/{guestFolio}/settle', [FolioController::class, 'settle']);
            Route::post('folios/{guestFolio}/reopen', [FolioController::class, 'reopen']);

            Route::get('operational-notifications', [FolioController::class, 'notifications']);
            Route::post('operational-notifications/{operationalNotification}/read', [FolioController::class, 'markNotificationRead']);

            // Phase 5: Restaurant & Bar POS, Room Service, Inventory & Procurement
            Route::get('inventory/dashboard', [InventoryController::class, 'dashboard']);
            Route::get('inventory/categories', [InventoryController::class, 'categories']);
            Route::post('inventory/categories', [InventoryController::class, 'storeCategory']);
            Route::get('inventory/locations', [InventoryController::class, 'locations']);
            Route::post('inventory/locations', [InventoryController::class, 'storeLocation']);
            Route::get('inventory/items', [InventoryController::class, 'items']);
            Route::post('inventory/items', [InventoryController::class, 'storeItem']);
            Route::put('inventory/items/{inventoryItem}', [InventoryController::class, 'updateItem']);
            Route::get('inventory/movements', [InventoryController::class, 'movements']);
            Route::post('inventory/movements', [InventoryController::class, 'recordMovement']);
            Route::post('inventory/stock-counts', [InventoryController::class, 'recordStockCount']);
            Route::post('inventory/transfers', [InventoryController::class, 'transfer']);
            Route::get('inventory/recipes', [InventoryController::class, 'recipes']);
            Route::post('inventory/recipes', [InventoryController::class, 'storeRecipe']);
            Route::delete('inventory/recipes/{menuItemRecipe}', [InventoryController::class, 'destroyRecipe']);

            Route::get('procurement/summary', [ProcurementController::class, 'summary']);
            Route::get('procurement/suppliers', [ProcurementController::class, 'suppliers']);
            Route::post('procurement/suppliers', [ProcurementController::class, 'storeSupplier']);
            Route::put('procurement/suppliers/{supplier}', [ProcurementController::class, 'updateSupplier']);
            Route::get('procurement/requisitions', [ProcurementController::class, 'requisitions']);
            Route::post('procurement/requisitions', [ProcurementController::class, 'storeRequisition']);
            Route::post('procurement/requisitions/{purchaseRequisition}/review', [ProcurementController::class, 'reviewRequisition']);
            Route::get('procurement/purchase-orders', [ProcurementController::class, 'purchaseOrders']);
            Route::post('procurement/purchase-orders', [ProcurementController::class, 'storePurchaseOrder']);
            Route::post('procurement/purchase-orders/{purchaseOrder}/review', [ProcurementController::class, 'reviewPurchaseOrder']);
            Route::post('procurement/purchase-orders/{purchaseOrder}/receive', [ProcurementController::class, 'receiveGoods']);
            Route::get('procurement/receivings', [ProcurementController::class, 'receivings']);

            Route::get('pos/outlets', [PosController::class, 'outlets']);
            Route::post('pos/outlets', [PosController::class, 'storeOutlet']);
            Route::put('pos/outlets/{posOutlet}', [PosController::class, 'updateOutlet']);
            Route::post('pos/prep-stations', [PosController::class, 'storePrepStation']);
            Route::get('pos/menu-categories', [PosController::class, 'menuCategories']);
            Route::post('pos/menu-categories', [PosController::class, 'storeMenuCategory']);
            Route::put('pos/menu-categories/{menuCategory}', [PosController::class, 'updateMenuCategory']);
            Route::get('pos/menu-items', [PosController::class, 'menuItems']);
            Route::post('pos/menu-items', [PosController::class, 'storeMenuItem']);
            Route::put('pos/menu-items/{menuItem}', [PosController::class, 'updateMenuItem']);
            Route::post('pos/menu-items/{menuItem}/variants', [PosController::class, 'storeVariant']);
            Route::post('pos/menu-items/{menuItem}/modifiers', [PosController::class, 'storeModifier']);
            Route::get('pos/in-house-stays', [PosController::class, 'inHouseStays']);
            Route::get('pos/orders', [PosController::class, 'orders']);
            Route::post('pos/orders', [PosController::class, 'storeOrder']);
            Route::get('pos/orders/{posOrder}', [PosController::class, 'showOrder']);
            Route::put('pos/orders/{posOrder}', [PosController::class, 'updateDraftOrder']);
            Route::post('pos/orders/{posOrder}/status', [PosController::class, 'transitionOrderStatus']);
            Route::post('pos/orders/{posOrder}/discount', [PosController::class, 'applyDiscount']);
            Route::post('pos/orders/{posOrder}/void', [PosController::class, 'voidOrder']);
            Route::post('pos/orders/{posOrder}/payments', [PosController::class, 'recordPayment']);
            Route::post('pos/payments/{posPayment}/refund', [PosController::class, 'refundPayment']);
            Route::post('pos/orders/{posOrder}/room-charge', [PosController::class, 'postRoomCharge']);
            Route::get('pos/reports/summary', [PosController::class, 'reportsSummary']);

            // Phase 6: Multi-Department Accounting, Night Audit, Financial Reporting & Analytics
            Route::get('accounting/accounts', [AccountingController::class, 'accounts']);
            Route::post('accounting/accounts', [AccountingController::class, 'storeAccount']);
            Route::get('accounting/journals', [AccountingController::class, 'journals']);
            Route::post('accounting/journals', [AccountingController::class, 'storeJournal']);
            Route::get('accounting/journals/{journalEntry}', [AccountingController::class, 'showJournal']);
            Route::post('accounting/journals/{journalEntry}/reverse', [AccountingController::class, 'reverseJournal']);
            Route::get('accounting/reports/trial-balance', [AccountingController::class, 'trialBalance']);
            Route::get('accounting/reports/income-statement', [AccountingController::class, 'incomeStatement']);
            Route::get('accounting/reports/balance-sheet', [AccountingController::class, 'balanceSheet']);

            Route::get('night-audit/checklist', [AccountingController::class, 'nightAuditChecklist']);
            Route::get('night-audit/audits', [AccountingController::class, 'nightAudits']);
            Route::post('night-audit/run', [AccountingController::class, 'runNightAudit']);

            Route::get('analytics/kpi-summary', [AccountingController::class, 'analytics']);
        });
    });
});
