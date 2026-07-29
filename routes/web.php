<?php

use App\Http\Controllers\Admin\StaticAdminShiftController;
use App\Http\Controllers\StaffShiftController;
use App\Http\Middleware\PreventStaffPageCaching;
use Illuminate\Support\Facades\Route;

/*
 * スタッフ画面にはキャッシュ禁止ミドルウェアを適用します。
 * これにより、通常リロードでも毎回サーバーから最新シフトを取得します。
 */
Route::middleware(PreventStaffPageCaching::class)->group(function (): void {
    // ルートページもスタッフ画面を表示し、既存のLaravel起動確認を維持します。
    Route::get('/', [StaffShiftController::class, 'top']);

    // スタッフ用画面は、認証導入前のためモックユーザーで表示します。
    Route::get('/staff', [StaffShiftController::class, 'top'])->name('staff.top');
    Route::get('/staff/store/{store}', [StaffShiftController::class, 'store'])->name('staff.store');
});

/*
 * 管理者用UIの静的確認ルートです。
 * この段階では認証・認可・保存API・配布処理へ接続せず、ダミーデータだけを表示します。
 */
Route::controller(StaticAdminShiftController::class)->group(function (): void {
    Route::get('/admin', 'store')->name('admin.top');
    Route::get('/admin/shifts/stores/{store}', 'store')->name('admin.shifts.stores.show');
    Route::get('/admin/shifts/staff/{staff?}', 'staff')->name('admin.shifts.staff.show');
});
