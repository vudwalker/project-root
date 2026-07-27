<?php

use App\Http\Controllers\StaffShiftController;
use Illuminate\Support\Facades\Route;

// ルートページもスタッフ画面を表示し、既存のLaravel起動確認を維持します。
Route::get('/', [StaffShiftController::class, 'top']);

// スタッフ用画面は、認証導入前のためモックユーザーで表示します。
Route::get('/staff', [StaffShiftController::class, 'top'])->name('staff.top');
Route::get('/staff/store/{store}', [StaffShiftController::class, 'store'])->name('staff.store');
