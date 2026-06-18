<?php

use Illuminate\Support\Facades\Route;
use ParseForArtisans\Http\WebhookController;

/*
 * The signed result callback (webhook delivery). The SaaS POSTs the terminal
 * result here; the controller verifies the X-Parse-Signature header before
 * firing the ParseCompleted / ParseFailed event.
 */
Route::post('/parse/webhook', WebhookController::class)->name('parse.webhook');
