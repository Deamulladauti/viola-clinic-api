<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Api\V1\PublicServiceController;
use App\Http\Controllers\Admin\ServiceCategoryController;
use App\Http\Controllers\Api\V1\PublicCategoryController;
use App\Http\Controllers\Api\V1\ServiceSignalsController;
use App\Http\Controllers\Admin\AppointmentAdminController;
use App\Http\Controllers\Public\AppointmentPublicController;
use App\Http\Controllers\Admin\StaffAdminController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MeAppointmentActionsController;
use App\Http\Controllers\Api\V1\StaffAppointmentController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\StaffPackageController;
use App\Http\Controllers\Api\V1\MePackageController;
use App\Http\Controllers\Api\V1\AdminPackageController;
use App\Http\Controllers\Api\V1\MeNotificationsController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\StaffProfileController;
use App\Http\Controllers\Api\V1\StaffClientController;
use App\Http\Controllers\Api\V1\StaffServiceController;
use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Support\Facades\Hash;


Route::prefix('v1')->group(function () {
    // ── Public ───────────────────────────────────────────────────────────────────
    Route::get('health', fn () => response()->json(['status' => 'ok']))->name('health');

    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login',    [AuthController::class, 'login'])->name('login');

    Route::prefix('auth')->group(function () {
    Route::post('forgot-password', [PasswordResetController::class, 'forgot']);
    Route::post('reset-password', [PasswordResetController::class, 'reset']);
});

    // Service public info (IDs only)
    Route::get('services',                   [PublicServiceController::class, 'index']);
    Route::get('services/suggest',           [PublicServiceController::class, 'suggest']);
    Route::get('services/{id}',              [PublicServiceController::class, 'show'])->whereNumber('id');

    // Categories public info (IDs only)
    Route::get('categories',                 [PublicCategoryController::class, 'index']);
    Route::get('categories/{id}/services',   [PublicCategoryController::class, 'servicesByCategory'])->whereNumber('id');
    Route::get('categories/{id}', [PublicCategoryController::class, 'show']);

    // Availability (day-level) and per-service availability/staff
    Route::get('availability',                           [AvailabilityController::class, 'day']);
    Route::get('services/{service}/availability', [AppointmentPublicController::class, 'availability'])
    ->whereNumber('service');

    Route::get('services/{service}/staff', [AppointmentPublicController::class, 'staffForService'])
    ->whereNumber('service');

    // Appointment lookup by public code (keep as code)
    Route::get('appointments/{code}', [AppointmentPublicController::class, 'showByCode']);

    // View signals (throttled)
    Route::post('signals/services/{id}/view', [ServiceSignalsController::class, 'view'])
        ->whereNumber('id')
        ->middleware('throttle:60,1');

    // Client booking (IDs only) — requires authenticated client
    Route::post('services/{service}/appointments', [AppointmentPublicController::class, 'guestBook'])
        ->whereNumber('service')
        ->middleware(['auth:sanctum','role:client','throttle:6,1']);
    // ── Authenticated (any role) ────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me',      [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
    });

    // ── Admin area ──────────────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum','role:admin'])->prefix('admin')->group(function () {

        Route::post('me/change-password', [MeController::class, 'changePassword']);

        // Categories
        Route::get   ('categories',                [ServiceCategoryController::class, 'index']);
        Route::post  ('categories',                [ServiceCategoryController::class, 'store']);
        Route::match (['put','patch'], 'categories/{category}', [ServiceCategoryController::class, 'update'])->whereNumber('category');
        Route::delete('categories/{category}',     [ServiceCategoryController::class, 'destroy'])->whereNumber('category');

        // Services
        Route::get   ('services',                  [ServiceController::class, 'index']);
        Route::post  ('services',                  [ServiceController::class, 'store']);
        Route::get   ('services/{id}',        [ServiceController::class, 'show'])->whereNumber('id');
        Route::match (['put','patch'], 'services/{id}', [ServiceController::class, 'update'])->whereNumber('id');
        Route::delete('services/{id}',        [ServiceController::class, 'destroy'])->whereNumber('id');

        // Appointments admin
        Route::get   ('appointments',              [AppointmentAdminController::class, 'index']);
        Route::post  ('appointments',              [AppointmentAdminController::class, 'store']);
        Route::patch ('appointments/{appointment}',[AppointmentAdminController::class, 'update'])->whereNumber('appointment');
        Route::delete('appointments/{appointment}',[AppointmentAdminController::class, 'destroy'])->whereNumber('appointment');
        Route::get   ('appointments/stats',        [AppointmentAdminController::class, 'stats']);
        Route::patch ('appointments/{appointment}/status', [AppointmentAdminController::class, 'updateStatus'])->whereNumber('appointment');
        Route::get('bookings/{appointment}', [AppointmentAdminController::class, 'showBooking']);
        Route::post('appointments/{appointment}/payments', [PaymentController::class, 'storeForAppointment'])->whereNumber('appointment');

        // Staff admin
        Route::get   ('staff',                     [StaffAdminController::class, 'index']);
        Route::post  ('staff',                     [StaffAdminController::class, 'store']);
        Route::patch ('staff/{staff}',             [StaffAdminController::class, 'update'])->whereNumber('staff');
        Route::delete('staff/{staff}',             [StaffAdminController::class, 'destroy'])->whereNumber('staff');
        


        Route::get('users', [StaffAdminController::class, 'listUsers']);
        Route::post('users/{user}/make-staff', [StaffAdminController::class, 'makeStaff'])->whereNumber('user');
        // Schedules
        Route::get   ('staff/{staff}/schedules',               [StaffAdminController::class, 'listSchedules'])->whereNumber('staff');
        Route::post  ('staff/{staff}/schedules',               [StaffAdminController::class, 'addSchedule'])->whereNumber('staff');
        Route::delete('staff/{staff}/schedules/{schedule}',    [StaffAdminController::class, 'removeSchedule'])->whereNumber('staff')->whereNumber('schedule');

        // Service links
        Route::post  ('staff/{staff}/services',                [StaffAdminController::class, 'syncServices'])->whereNumber('staff');

        // Time off
        Route::get   ('staff/{staff}/time-off',                [StaffAdminController::class, 'listTimeOff'])->whereNumber('staff');
        Route::post  ('staff/{staff}/time-off',                [StaffAdminController::class, 'addTimeOff'])->whereNumber('staff');
        Route::delete('staff/{staff}/time-off/{timeOff}',      [StaffAdminController::class, 'removeTimeOff'])->whereNumber('staff')->whereNumber('timeOff');

        // Packages (admin)
        Route::post  ('packages/assign',                       [AdminPackageController::class, 'assign']);
        Route::patch ('packages/{package}/status',             [AdminPackageController::class, 'updateStatus'])->whereNumber('package');
        Route::get   ('users/{user}/packages',                 [AdminPackageController::class, 'listForUser'])->whereNumber('user');
        Route::post  ('packages/{package}/payments',           [AdminPackageController::class, 'addPayment'])->whereNumber('package');
        

        // Appointment admin helpers
        Route::patch ('appointments/{appointment}/assign',     [AppointmentAdminController::class, 'assign'])->whereNumber('appointment');
        Route::get   ('appointments/{appointment}/logs',       [AppointmentAdminController::class, 'logs'])->whereNumber('appointment');
        Route::patch ('appointments/{appointment}/notes',      [AppointmentAdminController::class, 'updateNotes'])->whereNumber('appointment');

        Route::get('bookings',            [AppointmentAdminController::class, 'bookings']);        // GET /api/admin/bookings
        Route::get('bookings/filter',     [AppointmentAdminController::class, 'filterBookings']);  // GET /api/admin/bookings/filter (optional)
        Route::get('stats',               [AppointmentAdminController::class, 'dashboardStats']);  // GET /api/admin/stats
        Route::get('popular-services',    [AppointmentAdminController::class, 'popularServices']); // GET /api/admin/popular-services
    });

    // ── Client self area (role: client) ─────────────────────────────────────────
    Route::middleware(['auth:sanctum','role:client'])->group(function () {
        Route::get   ('me',                               [MeController::class, 'show']);
        Route::put   ('me',                               [MeController::class, 'update']);
        Route::post   ('me/avatar',                        [MeController::class, 'updateAvatar']);
        Route::delete('me/avatar',                        [MeController::class, 'deleteAvatar']);
        Route::post  ('me/change-password',               [MeController::class, 'changePassword']);
        Route::delete('me',         [MeController::class, 'deleteAccount']);

        Route::get   ('me/appointments',                  [MeController::class, 'appointments']);
        Route::delete('me/appointments/{id}',             [MeAppointmentActionsController::class, 'cancel'])->whereNumber('id');
        Route::patch ('me/appointments/{id}/reschedule',  [MeAppointmentActionsController::class, 'reschedule'])->whereNumber('id');

        Route::get   ('me/packages',                      [MePackageController::class, 'index']);
        Route::get   ('me/packages/{package}',            [MePackageController::class, 'show'])->whereNumber('package');

        Route::get   ('me/notifications',                 [MeNotificationsController::class, 'index']);
        Route::patch ('me/notifications/{id}/read',       [MeNotificationsController::class, 'markRead'])->whereNumber('id');
        Route::delete('me/notifications/{id}',            [MeNotificationsController::class, 'destroy'])->whereNumber('id');
    });


Route::prefix('staff')->middleware(['auth:sanctum','role:staff'])->group(function () {
    // Appointments list / agenda / today
    Route::get  ('appointments',                    [StaffAppointmentController::class, 'index']);
    Route::get  ('appointments/agenda',             [StaffAppointmentController::class, 'agenda']);
    Route::get  ('appointments/today',              [StaffAppointmentController::class, 'today']);
    Route::post('appointments/{appointment}/payments', [PaymentController::class, 'storeForAppointment'])->whereNumber('appointment');

    Route::get('me', [StaffProfileController::class, 'show']);
    Route::post('appointments', [StaffAppointmentController::class, 'store']);
    Route::get('clients/lookup', [StaffClientController::class, 'lookupByPhone']);
    Route::get('services', [StaffServiceController::class, 'index']);
    Route::get('me', [StaffProfileController::class, 'show']);
    Route::post('me/change-password', [MeController::class, 'changePassword']);
    


    // Single appointment
    Route::get  ('appointments/{appointment}',      [StaffAppointmentController::class, 'show'])->whereNumber('appointment');
    Route::patch('appointments/{appointment}',      [StaffAppointmentController::class, 'update'])->whereNumber('appointment');

    // Quick actions
    Route::patch('appointments/{appointment}/confirm',    [StaffAppointmentController::class, 'confirm'])->whereNumber('appointment');
    Route::patch('appointments/{appointment}/complete',   [StaffAppointmentController::class, 'complete'])->whereNumber('appointment');
    Route::patch('appointments/{appointment}/cancel',     [StaffAppointmentController::class, 'cancel'])->whereNumber('appointment');
    Route::patch('appointments/{appointment}/no-show',    [StaffAppointmentController::class, 'noShow'])->whereNumber('appointment');
    Route::patch('appointments/{appointment}/reschedule', [StaffAppointmentController::class, 'reschedule'])->whereNumber('appointment');

    // Staff-side packages utilities
    Route::get  ('packages',                          [StaffPackageController::class, 'index']);

    // Attach/detach package to an appointment
    Route::patch('appointments/{appointment}/attach-package', [StaffPackageController::class, 'attachToAppointment'])->whereNumber('appointment');
    Route::patch('appointments/{appointment}/detach-package', [StaffPackageController::class, 'detachFromAppointment'])->whereNumber('appointment');

    // Manual package usage (sessions/minutes)
    Route::post ('packages/{package}/use',            [StaffPackageController::class, 'usePackage'])->whereNumber('package');
    Route::post ('packages/{package}/payments',       [StaffPackageController::class, 'addPayment'])->whereNumber('package');
    Route::get('clients', [StaffClientController::class, 'search']);
    Route::post('packages', [StaffPackageController::class, 'store']);

    Route::get('clients/{client}/packages', [StaffPackageController::class, 'forClient'])->whereNumber('client');
    Route::get('packages/{package}/logs', [StaffPackageController::class, 'logs'])->whereNumber('package');



});
});
