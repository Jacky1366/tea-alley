<?php

use App\Models\StoreHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A user with one role. Run RoleSeeder first.
 */
function userWithRole(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

/**
 * Set store hours by updating the 7 rows the insert_default_store_hours migration
 * created. Never inserts rows: day_of_week is unique.
 *
 * @param  array<int, array{0: string, 1: string}|null>  $days  day_of_week => [opens_at, closes_at], or null for closed.
 *                                                              Days left out stay as they are (closed by default).
 */
function setStoreHours(array $days, int $cutoffMinutes = 15): void
{
    foreach ($days as $day => $hours) {
        StoreHour::where('day_of_week', $day)->firstOrFail()->update([
            'opens_at' => $hours[0] ?? null,
            'closes_at' => $hours[1] ?? null,
            'is_closed' => $hours === null,
            'ordering_cutoff_minutes' => $cutoffMinutes,
        ]);
    }
}

/**
 * Switch the app to the Stripe gateway with a dummy key, and install a fake for the SDK's
 * HTTP layer. Queue responses on the returned fake; nothing reaches the network.
 */
function useStripe(): FakeStripeHttpClient
{
    config(['payment.gateway' => 'stripe', 'services.stripe.secret' => 'sk_test_dummy']);
    app()->forgetInstance(StripeClient::class);

    return FakeStripeHttpClient::install();
}

/**
 * A Checkout Session as Stripe's API returns it (only the fields the app reads).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeSession(array $overrides = []): array
{
    $id = $overrides['id'] ?? 'cs_test_'.Str::random(24);

    return [
        'id' => $id,
        'object' => 'checkout.session',
        'status' => 'open',
        'payment_status' => 'unpaid',
        'url' => 'https://checkout.stripe.com/c/pay/'.$id,
        'expires_at' => now()->addMinutes(31)->getTimestamp(),
        'amount_total' => null,
        'currency' => 'cad',
        'metadata' => [],
        ...$overrides,
    ];
}
