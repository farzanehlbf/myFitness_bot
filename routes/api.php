<?php

use App\Http\Controllers\BaleWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/test', function () {
    return ['message' => 'API working'];
});
Route::post('/bale/webhook', [BaleWebhookController::class, 'handle']);
