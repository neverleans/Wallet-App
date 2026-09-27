<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WalletController;

/* Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
}); */

Route::post('/login', [AuthController::class, 'authenticate'])->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/home', [HomeController::class, 'show'])->name('home');

    Route::post('/transfer', [TransactionController::class, 'send'])->name('transaction.send');
    Route::post('/wallet', [WalletController::class, 'update'])->name('wallet.update');
});
