<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * #67: php artisan tea-alley:create-staff {email} {role}. By default a random password nobody
 * sees plus a password-reset email; --prompt asks for the password with hidden input.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
});

test('creates a verified staff account and emails a reset link; no password is shown', function (string $role) {
    $this->artisan('tea-alley:create-staff', ['email' => "Sam+{$role}@Example.com", 'role' => $role, '--name' => 'Sam'])
        ->expectsOutput("Created {$role} account sam+{$role}@example.com.")
        ->expectsOutput("Emailed sam+{$role}@example.com a link to choose a password.")
        ->assertSuccessful();

    $user = User::query()->where('email', "sam+{$role}@example.com")->sole();

    expect($user->name)->toBe('Sam')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->getRoleNames()->all())->toBe([$role])
        ->and(Hash::check('password', $user->password))->toBeFalse();

    Notification::assertSentTo($user, ResetPassword::class);
})->with(['barista', 'manager', 'admin']);

test('it works in production', function () {
    $this->app['env'] = 'production';

    try {
        $this->artisan('tea-alley:create-staff', ['email' => 'boss@example.com', 'role' => 'manager'])->assertSuccessful();
    } finally {
        $this->app['env'] = 'testing';
    }

    expect(User::query()->where('email', 'boss@example.com')->sole()->name)->toBe('Manager');
});

test('only barista, manager, or admin', function (string $role) {
    $this->artisan('tea-alley:create-staff', ['email' => 'x@example.com', 'role' => $role])
        ->expectsOutputToContain('The role must be one of: barista, manager, admin.')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['customer', 'owner', '']);

test('an existing email is refused and the account is left alone', function () {
    $existing = User::factory()->create(['email' => 'amy@example.com', 'name' => 'Amy']);
    $existing->assignRole('customer');

    $this->artisan('tea-alley:create-staff', ['email' => 'amy@example.com', 'role' => 'admin'])
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect($existing->fresh()->getRoleNames()->all())->toBe(['customer'])
        ->and(User::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

test('an invalid email is refused', function () {
    $this->artisan('tea-alley:create-staff', ['email' => 'not-an-email', 'role' => 'barista'])->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('--prompt: the password is asked twice with hidden input, and no email is sent', function () {
    $this->artisan('tea-alley:create-staff', ['email' => 'kim@example.com', 'role' => 'barista', '--prompt' => true])
        ->expectsQuestion('Password (hidden)', 'long enough secret')
        ->expectsQuestion('Password again', 'long enough secret')
        ->doesntExpectOutputToContain('long enough secret')
        ->assertSuccessful();

    $user = User::query()->where('email', 'kim@example.com')->sole();

    expect(Hash::check('long enough secret', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('--prompt uses the registration rules: 8+ characters and matching', function (string $password, string $again, string $message) {
    $this->artisan('tea-alley:create-staff', ['email' => 'kim@example.com', 'role' => 'barista', '--prompt' => true])
        ->expectsQuestion('Password (hidden)', $password)
        ->expectsQuestion('Password again', $again)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'too short' => ['short', 'short', 'at least 8 characters'],
    'not matching' => ['long enough secret', 'long enough secreT', 'confirmation does not match'],
]);

test('--prompt refuses a breached password (Have I Been Pwned)', function () {
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr(strtoupper(sha1('password123')), 5).':999')]);

    $this->artisan('tea-alley:create-staff', ['email' => 'kim@example.com', 'role' => 'barista', '--prompt' => true])
        ->expectsQuestion('Password (hidden)', 'password123')
        ->expectsQuestion('Password again', 'password123')
        ->expectsOutputToContain('data leak')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('the question is asked with hidden input', function () {
    $source = file_get_contents(app_path('Console/Commands/CreateStaff.php'));

    expect($source)->toContain("\$this->secret('Password (hidden)')")
        ->and($source)->toContain("\$this->secret('Password again')")
        ->and($source)->not->toContain('->ask(');
});
