<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ComplianceLookupController;

// Route::get('/compliance/lookup', [ComplianceLookupController::class, 'lookup']);
// Route::match(['get', 'post'], '/compliance/lookup', [ComplianceLookupController::class, 'lookup']);
Route::post('/compliance/lookup', [ComplianceLookupController::class, 'lookup']);

