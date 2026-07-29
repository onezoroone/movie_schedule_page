<?php

use App\Http\Controllers\CalendarController;
use Illuminate\Support\Facades\Route;

Route::get('/calendar', [CalendarController::class, 'show'])
    ->name('api.calendar');
