<?php

use App\Http\Controllers\Admin\AdminShiftController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\StaffShiftController;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureStaffAccess;
use App\Http\Middleware\PreventStaffPageCaching;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthenticatedSessionController::class, 'show'])->name('auth.home');
Route::get('/login', [AuthenticatedSessionController::class, 'show'])->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
Route::get('/access-unavailable', [AuthenticatedSessionController::class, 'unavailable'])
    ->middleware('auth')
    ->name('auth.access-unavailable');

/*
 * スタッフ画面にはキャッシュ禁止ミドルウェアを適用します。
 * これにより、通常リロードでも毎回サーバーから最新シフトを取得します。
 */
Route::middleware([
    'auth',
    EnsureStaffAccess::class,
    PreventStaffPageCaching::class,
])->group(function (): void {
    Route::get('/staff', [StaffShiftController::class, 'top'])->name('staff.top');
    Route::get('/staff/store/{store}', [StaffShiftController::class, 'store'])->name('staff.store');
});

/*
 * 管理者用UIは認証・認可後に、DBの下書きを読み取り専用で表示します。
 * 保存API、自動保存、配布処理にはまだ接続しません。
 */
Route::middleware(['auth', EnsureAdminAccess::class])
    ->controller(AdminShiftController::class)
    ->group(function (): void {
        Route::get('/admin', 'store')->name('admin.top');
        Route::get('/admin/shifts/stores/{store}', 'store')->name('admin.shifts.stores.show');
        Route::get('/admin/shifts/staff/{staff?}', 'staff')->name('admin.shifts.staff.show');
    });
