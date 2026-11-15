<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\StripePaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Model::shouldBeStrict(! $this->app->isProduction());

        // Livewire's update requests skip route middleware unless it's listed here. With this,
        // a page behind role:... (the barista board) checks the role on every action too.
        Livewire::addPersistentMiddleware([RoleMiddleware::class]);
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
