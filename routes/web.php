<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/requests', [DashboardController::class, 'requests'])->name('requests.index');
Route::get('/requests/create', [DashboardController::class, 'createRequest'])->name('requests.create');
Route::get('/requests/{id}', [DashboardController::class, 'showRequest'])->name('requests.show');
