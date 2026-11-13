<?php

use App\Http\Controllers\PasswordResetPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => ['app' => config('app.name'), 'api' => url('/api/v1')]);

// Password reset emails link here (see config/sira.php `password_reset_url`).
Route::get('reset-password', [PasswordResetPageController::class, 'show'])->name('password.reset');
Route::post('reset-password', [PasswordResetPageController::class, 'store'])->middleware('throttle:auth');
