<?php

use App\Http\Controllers\Admin\AdminShiftController;
use App\Http\Controllers\Admin\AdminShiftManagerController;
use App\Http\Controllers\Admin\AdminShiftMutationController;
use App\Http\Controllers\Admin\AdminShiftPublicationController;
use App\Http\Controllers\Admin\AdminShiftScheduleMemberController;
use App\Http\Controllers\Admin\AdminStaffController;
use App\Http\Controllers\Admin\AdminStoreController;
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
        Route::controller(AdminStaffController::class)->group(function (): void {
            Route::get('/admin/staff', 'index')->name('admin.staff.index');
            Route::get('/admin/staff/create', 'create')
                ->name('admin.staff.create');
            Route::post('/admin/staff', 'store')
                ->name('admin.staff.store');
            Route::get('/admin/staff/{user}/edit', 'edit')
                ->name('admin.staff.edit');
            Route::patch('/admin/staff/{user}', 'update')
                ->name('admin.staff.update');
        });

          Route::controller(AdminShiftManagerController::class)->group(function (): void {
              Route::get('/admin/shift-managers', 'index')
                  ->name('admin.shift-managers.index');
              Route::get('/admin/shift-managers/create', 'create')
                  ->name('admin.shift-managers.create');
              Route::post('/admin/shift-managers', 'store')
                  ->name('admin.shift-managers.store');
              Route::patch('/admin/shift-managers', 'update')
                  ->name('admin.shift-managers.update');
              Route::get('/admin/shift-managers/{user}/edit', 'edit')
                  ->name('admin.shift-managers.edit');
              Route::patch('/admin/shift-managers/{user}', 'updateProfile')
                  ->name('admin.shift-managers.profile.update');
        });

        Route::controller(AdminStoreController::class)->group(function (): void {
            Route::get('/admin/stores', 'index')->name('admin.stores.index');
            Route::get('/admin/stores/create', 'create')
                ->name('admin.stores.create');
            Route::post('/admin/stores', 'store')
                ->name('admin.stores.store');
            Route::get('/admin/stores/{store}/edit', 'edit')
                ->name('admin.stores.edit');
            Route::patch('/admin/stores/{store}', 'update')
                ->name('admin.stores.update');
            Route::get(
                '/admin/stores/{store}/staff-candidates',
                'staffCandidates',
            )->name('admin.stores.staff.candidates');
            Route::get(
                '/admin/stores/{store}/manager-candidates',
                'managerCandidates',
            )->name('admin.stores.manager.candidates');
        });

        Route::controller(AdminShiftController::class)->group(function (): void {
            Route::get('/admin', 'store')->name('admin.top');
            Route::get('/admin/shifts/stores/{store}', 'store')
                ->name('admin.shifts.stores.show');
            Route::get('/admin/shifts/staff/{staff?}', 'staff')
                ->name('admin.shifts.staff.show');
        });

        Route::controller(AdminShiftScheduleMemberController::class)->group(function (): void {
            Route::get(
                '/admin/shifts/stores/{store}/members',
                'index',
            )->name('admin.shifts.members');
            Route::post(
                '/admin/shifts/stores/{store}/members',
                'add',
            )->name('admin.shifts.members.add');
            Route::delete(
                '/admin/shifts/stores/{store}/members/{user}',
                'remove',
            )->name('admin.shifts.members.remove');
            Route::patch(
                '/admin/shifts/stores/{store}/members/order',
                'reorder',
            )->name('admin.shifts.members.reorder');
        });

        Route::controller(AdminShiftMutationController::class)->group(function (): void {
            Route::post('/admin/shifts/stores/{store}/shifts', 'store')
                ->name('admin.shifts.store');
            Route::patch('/admin/shifts/stores/{store}/shifts/{shift}', 'update')
                ->name('admin.shifts.update');
            Route::delete('/admin/shifts/stores/{store}/shifts/{shift}', 'destroy')
                ->name('admin.shifts.destroy');
        });

        Route::post(
            '/admin/shifts/stores/{store}/publish',
            [AdminShiftPublicationController::class, 'store'],
        )->name('admin.shifts.publish');
    });
