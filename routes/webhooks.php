<?php

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Server-to-server endpoints, loaded outside the "web" group (bootstrap/app.php): no
 * session, no cookies, no CSRF token. Each one authenticates the caller itself.
 */

// Stripe Checkout events. The Stripe-Signature header is verified in the controller.
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
