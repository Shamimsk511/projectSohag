<?php

use App\Enums\Role;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\CashHandoverController;
use App\Http\Controllers\Admin\CollectionPlanController;
use App\Http\Controllers\Admin\DailyClosingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\OfficeEntryController;
use App\Http\Controllers\Admin\OfficeHeadController;
use App\Http\Controllers\Admin\OpeningBalanceController;
use App\Http\Controllers\Admin\PartyAssignmentController;
use App\Http\Controllers\Admin\PartyController;
use App\Http\Controllers\Admin\PartyPhotoUploadController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Field\CollectionController;
use App\Http\Controllers\Field\CollectionSyncController;
use App\Http\Controllers\Field\PartyCollectionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

Route::get('/', HomeController::class)->middleware('auth')->name('home');

Auth::routes(['register' => false]);

Route::middleware(['auth', RoleMiddleware::using(Role::names(Role::office()))])
    ->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('dashboard/live', [DashboardController::class, 'live'])->name('dashboard.live');
        Route::get('search', SearchController::class)->name('search');
        Route::get('reports/daily-collection', [ReportController::class, 'dailyCollection'])->name('reports.daily-collection');
        Route::get('reports/officer-performance', [ReportController::class, 'officerPerformance'])->name('reports.officer-performance');
        Route::get('reports/outstanding', [ReportController::class, 'outstanding'])->name('reports.outstanding');
        Route::get('reports/missed-partial', [ReportController::class, 'missedPartial'])->name('reports.missed-partial');
        Route::get('reports/cash-reconciliation', [ReportController::class, 'cashReconciliation'])->name('reports.cash-reconciliation');
        Route::get('reports/monthly', [ReportController::class, 'monthly'])->name('reports.monthly');
        Route::get('reports/loan-register', [ReportController::class, 'loanRegister'])->name('reports.loan-register');
        Route::get('reports/withdrawal-register', [ReportController::class, 'withdrawalRegister'])->name('reports.withdrawal-register');
        Route::get('reports/daily-cash-book', [ReportController::class, 'dailyCashBook'])->name('reports.daily-cash-book');
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::resource('areas', AreaController::class)->except(['show', 'destroy']);
        Route::get('parties/data', [PartyController::class, 'data'])->name('parties.data');
        Route::get('parties/{party}/statement', [PartyController::class, 'statement'])->name('parties.statement');
        Route::get('parties/{party}/photo', [PartyController::class, 'photo'])->name('parties.photo');
        Route::resource('parties', PartyController::class)->except(['destroy']);
        Route::post('parties/{party}/assignment', [PartyAssignmentController::class, 'store'])->name('parties.assignment.store');
        Route::delete('parties/{party}/assignment', [PartyAssignmentController::class, 'destroy'])->name('parties.assignment.destroy');
        Route::get('parties/{party}/accounts/create', [AccountController::class, 'create'])->name('parties.accounts.create');
        Route::post('parties/{party}/accounts', [AccountController::class, 'store'])->name('parties.accounts.store');
        Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        Route::get('accounts/{account}/statement', [AccountController::class, 'statement'])->name('accounts.statement');
        Route::post('accounts/{account}/adjustments', [AccountController::class, 'adjust'])->name('accounts.adjust');
        Route::post('accounts/{account}/close', [AccountController::class, 'close'])->name('accounts.close');
        Route::put('accounts/{account}/collection-plan', [CollectionPlanController::class, 'update'])->name('accounts.collection-plan.update');
        Route::post('accounts/{account}/schedules', [ScheduleController::class, 'store'])->name('accounts.schedules.store');
        Route::get('schedules/print', [ScheduleController::class, 'print'])->name('schedules.print');
        Route::post('schedules/reassign', [ScheduleController::class, 'reassign'])->name('schedules.reassign');
        Route::post('schedules/{schedule}/cancel', [ScheduleController::class, 'cancel'])->name('schedules.cancel');
        Route::resource('schedules', ScheduleController::class)->only(['index', 'edit', 'update']);
        Route::resource('holidays', HolidayController::class)->only(['index', 'store', 'destroy']);
        Route::post('accounts/{account}/transactions', [TransactionController::class, 'store'])->name('accounts.transactions.store');
        Route::get('office-entries', [OfficeEntryController::class, 'index'])->name('office-entries.index');
        Route::post('office-entries', [OfficeEntryController::class, 'store'])->name('office-entries.store');
        Route::post('accounts/{account}/office-income', [OfficeEntryController::class, 'storeForAccount'])->name('accounts.office-income.store');
        Route::resource('office-heads', OfficeHeadController::class)->except(['show', 'destroy']);
        Route::resource('cash-handovers', CashHandoverController::class)->only(['index', 'store']);
        Route::resource('daily-closings', DailyClosingController::class)->only(['index', 'store']);
        Route::get('opening-cash', [OpeningBalanceController::class, 'index'])->name('opening-cash.index');
        Route::post('opening-cash', [OpeningBalanceController::class, 'store'])->name('opening-cash.store');
        Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::get('transactions/{transaction}/receipt', [ReceiptController::class, 'show'])->name('transactions.receipt');
        Route::post('transactions/{transaction}/reverse', [TransactionController::class, 'reverse'])->name('transactions.reverse');
        Route::post('uploads/party-photo', [PartyPhotoUploadController::class, 'store'])->name('uploads.party-photo.store');
        Route::delete('uploads/party-photo', [PartyPhotoUploadController::class, 'destroy'])->name('uploads.party-photo.destroy');
    });

// Public: the service worker precaches it as the offline fallback for /field pages.
Route::view('field/offline', 'field.offline')->name('field.offline');

Route::middleware(['auth', RoleMiddleware::using(Role::names([Role::FieldOfficer, Role::Admin]))])
    ->prefix('field')->name('field.')->group(function () {
        Route::get('/', [CollectionController::class, 'index'])->name('home');
        Route::get('schedules/{schedule}/collect', [CollectionController::class, 'create'])->name('collections.create');
        Route::post('schedules/{schedule}/collections', [CollectionController::class, 'store'])->name('collections.store');
        Route::get('parties/{party}/collect', [PartyCollectionController::class, 'create'])->name('parties.collect');
        Route::post('parties/{party}/collections', [PartyCollectionController::class, 'store'])->name('parties.collections.store');
        Route::get('parties/{party}/photo', [PartyCollectionController::class, 'photo'])->name('parties.photo');
        Route::get('receipts/{transaction}', [ReceiptController::class, 'show'])->name('receipts.show');
        // Filled by field.js from this phone's offline queue (IndexedDB); the server has nothing to add.
        Route::view('sync', 'field.sync')->name('sync');
    });

// PWA sync: JSON under /api/field but on the web stack (session cookie + CSRF header), see docs/PWA.md.
Route::middleware(['auth', RoleMiddleware::using(Role::names([Role::FieldOfficer, Role::Admin]))])
    ->prefix('api/field')->name('field.api.')->group(function () {
        Route::post('collections/sync', CollectionSyncController::class)->middleware('throttle:60,1')->name('collections.sync');
    });
