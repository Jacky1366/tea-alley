<?php

use App\Enums\OrderStatus;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Cart;
use App\Services\ModifierValidator;
use App\Support\ActionLimiter;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * T3-3 (#51): rate limits. Logged-in actions count per user, never per IP: a classroom (or
 * the demo tunnel) puts everyone behind one address. Every test request here comes from the
 * same IP unless it says otherwise.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    setStoreHours(array_fill(0, 7, ['00:00:00', '23:59:00']));
    Notification::fake();
});

/**
 * Uses up $user's attempts for $action, as if they had just made them.
 */
function exhaust(string $action, User $user): void
{
    foreach (range(1, ActionLimiter::PER_MINUTE[$action]) as $attempt) {
        expect(ActionLimiter::attempt($action, $user))->toBeNull();
    }
}

describe('checkout: 5 orders a minute per user', function () {
    beforeEach(function () {
        app(Cart::class)->add(app(ModifierValidator::class)->validate(MenuItem::factory()->create(), [], 1));
    });

    test('the 6th attempt in a minute shows a message and places nothing', function () {
        $user = userWithRole('customer');
        exhaust(ActionLimiter::CHECKOUT, $user);

        Livewire::actingAs($user)->test('pages::checkout')
            ->call('placeOrder')
            ->assertHasErrors(['order'])
            ->assertSee('Too many attempts. Please wait')
            ->assertNoRedirect();

        expect(Order::count())->toBe(0);
    });

    test('another user on the same IP is not affected', function () {
        exhaust(ActionLimiter::CHECKOUT, userWithRole('customer'));

        Livewire::actingAs(userWithRole('customer'))->test('pages::checkout')
            ->call('placeOrder')
            ->assertHasNoErrors();

        expect(Order::count())->toBe(1);
    });

    test('the 5th attempt still goes through', function () {
        $user = userWithRole('customer');
        foreach (range(1, 4) as $attempt) {
            ActionLimiter::attempt(ActionLimiter::CHECKOUT, $user);
        }

        Livewire::actingAs($user)->test('pages::checkout')->call('placeOrder')->assertHasNoErrors();

        expect(Order::count())->toBe(1);
    });

    test('the message is translated', function () {
        $user = userWithRole('customer');
        $user->forceFill(['locale' => 'zh_TW'])->save();
        exhaust(ActionLimiter::CHECKOUT, $user);
        app()->setLocale('zh_TW');

        Livewire::actingAs($user)->test('pages::checkout')->call('placeOrder')->assertSee('嘗試次數過多');
    });
});

describe('retry payment: 5 a minute per user', function () {
    test('over the limit, the receipt says so and the payment isn\'t started', function () {
        $owner = userWithRole('customer');
        $order = Order::factory()->for($owner)->create(['status' => OrderStatus::PendingPayment]);
        exhaust(ActionLimiter::RETRY_PAYMENT, $owner);

        Livewire::actingAs($owner)->test('pages::orders.show', ['order' => $order])
            ->call('retryPayment')
            ->assertSee('Too many attempts. Please wait')
            ->assertNoRedirect();

        // The fake gateway would have marked it paid.
        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    test('another customer on the same IP can still retry their own order', function () {
        exhaust(ActionLimiter::RETRY_PAYMENT, userWithRole('customer'));
        $other = userWithRole('customer');
        $order = Order::factory()->for($other)->create(['status' => OrderStatus::PendingPayment]);

        Livewire::actingAs($other)->test('pages::orders.show', ['order' => $order])->call('retryPayment');

        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });
});

describe('register: 30 per 10 minutes per IP, 100 an hour in total', function () {
    /** An invalid sign-up: cheap, and the limiter counts it like any other. */
    function signUpFrom(string $ip): TestResponse
    {
        return test()->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('register.store'), []);
    }

    test('the 31st sign-up from one IP gets the 429 page; another IP is fine', function () {
        foreach (range(1, 30) as $attempt) {
            signUpFrom('203.0.113.10')->assertRedirect();
        }

        signUpFrom('203.0.113.10')->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee('Too many requests')
            ->assertSee('請求次數過多');

        signUpFrom('203.0.113.11')->assertRedirect();
    });

    test('after 100 sign-ups in an hour from anywhere, the next one waits', function () {
        foreach (range(1, 100) as $attempt) {
            signUpFrom('198.51.100.'.intdiv($attempt, 25))->assertRedirect();
        }

        signUpFrom('192.0.2.200')->assertStatus(429);
    });

    test('a real sign-up still works', function () {
        $this->post(route('register.store'), [
            'name' => 'Mei',
            'email' => 'mei@example.com',
            'password' => 'long enough',
            'password_confirmation' => 'long enough',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    });
});

describe('forgot password: 5 a minute per IP', function () {
    test('the 6th request from one IP gets the 429 page; another IP is fine', function () {
        $send = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('password.email'), ['email' => 'someone@example.com']);

        foreach (range(1, 5) as $attempt) {
            $send('203.0.113.20')->assertRedirect();
        }

        $send('203.0.113.20')->assertStatus(429);
        $send('203.0.113.21')->assertRedirect();
    });
});

describe('login: Fortify\'s 5 a minute per email and IP', function () {
    test('one person\'s failed logins don\'t lock out someone else on the same IP', function () {
        $victim = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), ['email' => $victim->email, 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['email' => $victim->email, 'password' => 'wrong'])->assertStatus(429);

        $this->post(route('login.store'), ['email' => $other->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($other);
    });
});
