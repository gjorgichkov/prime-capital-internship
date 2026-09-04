<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::apiResource('clients', ClientController::class)->only(['index', 'store', 'show']);

Route::apiResource('clients.transactions', TransactionController::class)->only(['index']);
