<?php

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
