<?php

use App\Http\Controllers\Api\Auth\AuthController;
use Illuminate\Support\Facades\Route;

// Closure EMAS — `route:cache` bilan mos (cacheable).
Route::view('/', 'welcome');

// `login` nomli route — himoyalangan route'ga guest urilganda `Authenticate`
// middleware `route('login')`ни ишлатади. Nom bo'lmasa 500. Bu action toza JSON
// 401 qaytaradi (controller-backed — cacheable, closure emas).
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
