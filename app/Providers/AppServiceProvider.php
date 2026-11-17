<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\StripePaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\Middleware\RoleMiddleware;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PAYMENT_GATEWAY picks the gateway; tests and CI always run with "fake" (phpunit.xml).
        $this->app->bind(PaymentGateway::class, fn (Application $app): PaymentGateway => match (config('payment.gateway')) {
            'stripe' => $app->make(StripePaymentGateway::class),
            'fake' => $app->make(FakePaymentGateway::class),
            default => throw new InvalidArgumentException('PAYMENT_GATEWAY must be "stripe" or "fake".'),
        });

        // Built only when the Stripe gateway is used. An empty key is passed as null: the SDK
        // rejects "" outright, and a missing key should fail on the first request instead.
        $this->app->singleton(StripeClient::class, function (): StripeClient {
            // StripePaymentGateway calls Stripe with the order row locked, so a slow Stripe must
            // not hold that lock for the SDK's defaults (30 s to connect, 80 s per request).
            // The timeouts live on the SDK's shared curl client, the one it uses unless another
            // is installed (tests install their own, which this leaves alone).
            CurlClient::instance()
                ->setConnectTimeout(StripePaymentGateway::CONNECT_TIMEOUT_SECONDS)
                ->setTimeout(StripePaymentGateway::TIMEOUT_SECONDS);

            return new StripeClient([
                'api_key' => config('services.stripe.secret') ?: null,
                // The SDK adds an idempotency key to a retried POST. One retry keeps the worst
                // case near 2 x TIMEOUT_SECONDS.
                'max_network_retries' => StripePaymentGateway::NETWORK_RETRIES,
            ]);
        });

        // Password::uncompromised() asks Have I Been Pwned. Laravel waits up to 30 s; a slow or
        // unreachable service would stall registration that long. It fails open (an error or a
        // non-2xx answer counts as "not breached"), so a short timeout only costs the check.
        // extend(), not singleton(): the validation provider is deferred and binds its own
        // verifier when first used, which would replace a binding made here.
        $this->app->extend(UncompromisedVerifier::class, fn (UncompromisedVerifier $verifier, Application $app): UncompromisedVerifier => new NotPwnedVerifier(
            $app->make(HttpFactory::class),
            self::BREACHED_PASSWORD_TIMEOUT_SECONDS,
        ));
    }

    /** How long a password check waits for Have I Been Pwned before skipping it. */
    public const BREACHED_PASSWORD_TIMEOUT_SECONDS = 3;

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Model::shouldBeStrict(! $this->app->isProduction());

        // Livewire's update requests skip route middleware unless it's listed here. With this,
        // a page behind role:... (the barista board) checks the role on every action too, and a
        // page behind verified (checkout, orders) checks it too (T3-3). Livewire already
        // re-applies Authenticate and Authorize (can:...) by default.
        Livewire::addPersistentMiddleware([RoleMiddleware::class, EnsureEmailIsVerified::class]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // T3-3 (NIST SP 800-63B): length plus a breached-password check, no composition rules,
        // the same in every environment. See docs/decisions.md.
        Password::defaults(fn (): Password => Password::min(8)->uncompromised());
    }
}
