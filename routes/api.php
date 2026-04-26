<?php

use App\Http\Controllers\Api\DiagnosisController;
use Illuminate\Support\Facades\Route;

Route::post('/diagnosis/analyze', [DiagnosisController::class, 'analyze']);
