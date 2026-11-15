<?php

namespace App\Http\Controllers;

use App\Services\Payments\StripeWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * POST /stripe/webhook. No login, no session, no CSRF (routes/webhooks.php is outside the
 * web group): the Stripe-Signature check IS the authorization.
 *
 * - Bad or missing signature (or no STRIPE_WEBHOOK_SECRET configured): 400.
 * - A verified event: 200, whether or not it could be applied. StripeWebhookHandler logs
 *   anything it can't apply (unknown order, amount mismatch, illegal transition, conflict)
 *   instead of throwing, because any other answer makes Stripe resend it for days.
 * - Anything else that goes wrong (the database is down) is not caught: the 500 makes Stripe
 *   retry, which is what should happen when the event could be applied later.
 */
class StripeWebhookController
{
    public function __invoke(Request $request, StripeWebhookHandler $handler): Response
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            Log::error('STRIPE_WEBHOOK_SECRET is not set; Stripe webhooks are rejected.');

            return response('Webhook not configured.', 400);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->headers->get('Stripe-Signature') ?? '',
                $secret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid signature.', 400);
        }

        $handler->handle($event);

        return response('', 200);
    }
}
