<?php

use App\Http\Controllers\Admin\AdminShiftController;
use App\Http\Controllers\Admin\AdminShiftMutationController;
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
 * 管理者用店舗別シフト編集画面だけを書き込みAPIへ接続します。
 * 管理者用スタッフ別シフト確認画面は閲覧専用のままとし、配布処理には接続しません。
 */
Route::middleware(['auth', EnsureAdminAccess::class])
    ->group(function (): void {
        Route::controller(AdminShiftController::class)->group(function (): void {
            Route::get('/admin', 'store')->name('admin.top');
            Route::get('/admin/shifts/stores/{store}', 'store')
                ->name('admin.shifts.stores.show');
            Route::get('/admin/shifts/staff/{staff?}', 'staff')
                ->name('admin.shifts.staff.show');
        });

        Route::controller(AdminShiftMutationController::class)->group(function (): void {
            Route::post('/admin/shifts/stores/{store}/shifts', 'store')
                ->name('admin.shifts.store');
            Route::patch('/admin/shifts/stores/{store}/shifts/{shift}', 'update')
                ->name('admin.shifts.update');
            Route::delete('/admin/shifts/stores/{store}/shifts/{shift}', 'destroy')
                ->name('admin.shifts.destroy');
        });
    });
