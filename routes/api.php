<?php

use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyGitHubWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/webhook/github', [WebhookController::class, 'handle'])
    ->middleware(VerifyGitHubWebhook::class);
