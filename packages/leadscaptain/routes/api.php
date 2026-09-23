<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Leadscaptain\Presentation\Http\Controllers\LeadController;

// Prefix and middleware come from config('leadscaptain.routes').
Route::get('leads', [LeadController::class, 'index'])->name('leadscaptain.leads.index');
