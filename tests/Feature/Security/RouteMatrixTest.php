<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\StoreHour;
use App\Models\User;
use App\Services\Cart;
use App\Services\ModifierValidator;
use App\Support\PickupQr;
use Database\Seeders\RoleSeeder;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * T3-3 (#51): every route × every role. One row per route: what a guest, customer, barista,
 * manager, and admin get. Outcomes:
 *   200 / 400 / 403 / 404     that status
 *   'login'                   302 to /login
 *   '→ /path'                 302 to that path
 * Livewire actions are covered by LivewireActionAccessTest (they don't go through these routes).
 * The guard test at the bottom fails when a registered route is in neither ROUTE_MATRIX nor
 * ROUTE_MATRIX_EXCLUDED, so a new route (e.g. T3-1's /barista/scan) can't be forgotten.
 */

const MATRIX_ROLES = ['guest', 'customer', 'barista', 'manager', 'admin'];

/**
 * "METHOD uri" => [path, guest, customer, barista, manager, admin]. Placeholders in the path:
 * {item} a drink, {own-order} an order of the acting user, {other-order} someone else's,
 * {category}, {group}, {store-hour}, {token}, and {with-cart} (one drink in the cart, no text).
 */
const ROUTE_MATRIX = [
    // Public pages
    'GET /' => ['/', 200, 200, 200, 200, 200],
    'GET menu/{item}' => ['/menu/{item}', 200, 200, 200, 200, 200],
    'GET cart' => ['/cart', 200, 200, 200, 200, 200],
    'POST locale' => ['/locale', '→ /', '→ /', '→ /', '→ /', '→ /'],
    'GET .well-known/passkey-endpoints' => ['/.well-known/passkey-endpoints', 200, 200, 200, 200, 200],

    // Ordering (auth + verified; the order route also OrderPolicy::view)
    'GET checkout' => ['/checkout{with-cart}', 'login', 200, 200, 200, 200],
    'GET orders' => ['/orders', 'login', 200, 200, 200, 200],
    'GET orders/{order}' => ['/orders/{own-order}', 'login', 200, 200, 200, 200],
    'GET orders/{order} (someone else\'s)' => ['/orders/{other-order}', 'login', 404, 404, 404, 404],
    'GET dashboard' => ['/dashboard', 'login', '→ /', '→ /barista', '→ /admin', '→ /admin'],

    // Barista board (role:barista|manager|admin)
    'GET barista' => ['/barista', 'login', 403, 200, 200, 200],

    // T3-1 pickup QR (role:barista|manager|admin; the pickup page also signed:relative)
    'GET barista/scan' => ['/barista/scan', 'login', 403, 200, 200, 200],
    'GET barista/pickup/{order}' => ['/barista/pickup/{signed-pickup}', 'login', 403, 200, 200, 200],
    'GET barista/pickup/{order} (tampered signature)' => ['/barista/pickup/{tampered-pickup}', 'login', 403, 403, 403, 403],
    'GET barista/pickup/{order} (no signature)' => ['/barista/pickup/{unsigned-pickup}', 'login', 403, 403, 403, 403],

    // Settings
    'ANY settings' => ['/settings', 'login', '→ /settings/profile', '→ /settings/profile', '→ /settings/profile', '→ /settings/profile'],
    'GET settings/profile' => ['/settings/profile', 'login', 200, 200, 200, 200],
    'GET settings/appearance' => ['/settings/appearance', 'login', 200, 200, 200, 200],
    'GET settings/security' => ['/settings/security', 'login', '→ /user/confirm-password', '→ /user/confirm-password', '→ /user/confirm-password', '→ /user/confirm-password'],

    // Auth pages (Fortify): guests only; a logged-in user goes to their home via /dashboard
    'GET login' => ['/login', 200, '→ /dashboard', '→ /dashboard', '→ /dashboard', '→ /dashboard'],
    'GET register' => ['/register', 200, '→ /dashboard', '→ /dashboard', '→ /dashboard', '→ /dashboard'],
    'GET forgot-password' => ['/forgot-password', 200, '→ /dashboard', '→ /dashboard', '→ /dashboard', '→ /dashboard'],
    'GET reset-password/{token}' => ['/reset-password/{token}', 200, '→ /dashboard', '→ /dashboard', '→ /dashboard', '→ /dashboard'],
    'GET two-factor-challenge' => ['/two-factor-challenge', 'login', '→ /dashboard', '→ /dashboard', '→ /dashboard', '→ /dashboard'],
    'GET email/verify' => ['/email/verify', 'login', '→ /dashboard', '→ /dashboard', '→ /dashboard', '→ /dashboard'],
    'GET user/confirm-password' => ['/user/confirm-password', 'login', 200, 200, 200, 200],
    'POST logout' => ['/logout', 'login', '→ /', '→ /', '→ /', '→ /'],

    // Stripe webhook: no session; the signature is the only way in
    'POST stripe/webhook' => ['/stripe/webhook', 400, 400, 400, 400, 400],

    // Admin panel (canAccessPanel: manager, admin; each resource's policy)
    'GET admin' => ['/admin', 'login', 403, 403, 200, 200],
    'GET admin/categories' => ['/admin/categories', 'login', 403, 403, 200, 200],
    'GET admin/categories/create' => ['/admin/categories/create', 'login', 403, 403, 200, 200],
    'GET admin/categories/{record}/edit' => ['/admin/categories/{category}/edit', 'login', 403, 403, 200, 200],
    'GET admin/menu-items' => ['/admin/menu-items', 'login', 403, 403, 200, 200],
    'GET admin/menu-items/create' => ['/admin/menu-items/create', 'login', 403, 403, 200, 200],
    'GET admin/menu-items/{record}/edit' => ['/admin/menu-items/{item}/edit', 'login', 403, 403, 200, 200],
    'GET admin/modifier-groups' => ['/admin/modifier-groups', 'login', 403, 403, 200, 200],
    'GET admin/modifier-groups/create' => ['/admin/modifier-groups/create', 'login', 403, 403, 200, 200],
    'GET admin/modifier-groups/{record}/edit' => ['/admin/modifier-groups/{group}/edit', 'login', 403, 403, 200, 200],
    'GET admin/store-hours' => ['/admin/store-hours', 'login', 403, 403, 200, 200],
    'GET admin/store-hours/{record}/edit' => ['/admin/store-hours/{store-hour}/edit', 'login', 403, 403, 200, 200],
    'GET admin/audit-logs' => ['/admin/audit-logs', 'login', 403, 403, 403, 200],
    // Filament's logout response goes back to the panel, which then sends the guest to log in.
    'POST admin/logout' => ['/admin/logout', 'login', 403, 403, '→ /admin', '→ /admin'],
];

/**
 * Registered routes deliberately left out of the matrix, each with the reason. Keys are
 * "METHOD uri", or a prefix ending in "*".
 */
const ROUTE_MATRIX_EXCLUDED = [
    'GET up' => 'Health check: returns no data.',
    'POST livewire-*' => 'Livewire endpoints. /update is covered by LivewireActionAccessTest; /upload-file needs a signed URL from a component.',
    'GET livewire-*' => 'Livewire\'s JS and CSS assets, and /preview-file (signed URLs only).',
    'GET flux/*' => 'Flux\'s static JS and CSS.',
    'GET filament/exports/*' => 'Filament export downloads: we have no export actions, and Filament checks the export belongs to the user.',
    'GET filament/imports/*' => 'Filament import failure downloads: we have no import actions, and Filament checks ownership.',
    'GET broadcasting/auth' => 'Channel auth: OrderBroadcastTest covers who may join each private channel.',
    'POST login' => 'Fortify: tests/Feature/Auth/AuthenticationTest; throttled by email|ip (FortifyServiceProvider).',
    'POST register' => 'Fortify: tests/Feature/Auth/RegistrationTest; throttled by RateLimitTest.',
    'POST forgot-password' => 'Fortify: tests/Feature/Auth/PasswordResetTest; throttled by RateLimitTest.',
    'POST reset-password' => 'Fortify: tests/Feature/Auth/PasswordResetTest (needs a valid token).',
    'POST two-factor-challenge' => 'Fortify: tests/Feature/Auth/TwoFactorChallengeTest; throttled per login attempt.',
    'GET email/verify/{id}/{hash}' => 'Fortify: signed URL; tests/Feature/Auth/EmailVerificationTest.',
    'POST email/verification-notification' => 'Fortify: auth + throttle:6,1; tests/Feature/Auth/EmailVerificationTest.',
    'GET user/*' => 'Fortify 2FA and password-confirmation JSON endpoints and passkey options: auth + password.confirm (see route:list); tests/Feature/Settings.',
    'POST user/*' => 'Fortify 2FA, password confirmation, and passkey registration: auth + password.confirm; tests/Feature/Settings.',
    'DELETE user/*' => 'Fortify 2FA disable and passkey delete: auth + password.confirm; tests/Feature/Settings.',
    'GET passkeys/*' => 'Passkey login and confirmation options: throttled (passkeys limiter); package-tested.',
    'POST passkeys/*' => 'Passkey login and confirmation: throttled (passkeys limiter); package-tested.',
];

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    setStoreHours(array_fill(0, 7, ['00:00:00', '23:59:00']));
});

/**
 * "METHOD uri" for a route, with HEAD dropped.
 */
function routeKey(RouteDefinition $route): string
{
    $methods = array_values(array_diff($route->methods(), ['HEAD']));

    return (count($methods) > 2 ? 'ANY' : $methods[0]).' '.$route->uri();
}

function matrixUser(string $role): ?User
{
    return $role === 'guest' ? null : userWithRole($role);
}

function matrixPath(string $path, ?User $user): string
{
    $replace = [
        '{item}' => fn () => MenuItem::factory()->create()->id,
        '{own-order}' => fn () => Order::factory()->for($user ?? User::factory()->create())->create()->order_number,
        '{other-order}' => fn () => Order::factory()->create()->order_number,
        '{category}' => fn () => Category::factory()->create()->id,
        '{group}' => fn () => ModifierGroup::factory()->create()->id,
        '{store-hour}' => fn () => StoreHour::query()->value('id'),
        '{token}' => fn () => 'any-token',
        // A Ready order's pickup link, as in its QR: "TA-123456?signature=...", tampered, or unsigned.
        '{signed-pickup}' => fn () => Str::after(PickupQr::path(Order::factory()->ready()->create()), '/barista/pickup/'),
        '{tampered-pickup}' => fn () => Str::after(PickupQr::path(Order::factory()->ready()->create()), '/barista/pickup/').'0',
        '{unsigned-pickup}' => fn () => Order::factory()->ready()->create()->order_number,
        // Checkout sends an empty cart back to the cart page: put one drink in it first.
        '{with-cart}' => function () {
            app(Cart::class)->add(app(ModifierValidator::class)->validate(MenuItem::factory()->create(), [], 1));

            return '';
        },
    ];

    foreach ($replace as $placeholder => $value) {
        if (str_contains($path, $placeholder)) {
            $path = str_replace($placeholder, (string) $value(), $path);
        }
    }

    return $path;
}

test('route × role', function (string $route, string $role) {
    [$path, $expected] = [ROUTE_MATRIX[$route][0], ROUTE_MATRIX[$route][array_search($role, MATRIX_ROLES, true) + 1]];
    $user = matrixUser($role);
    $method = str_starts_with($route, 'ANY') ? 'GET' : explode(' ', $route)[0];

    $response = ($user ? $this->actingAs($user) : $this)->call($method, matrixPath($path, $user));

    match (true) {
        $expected === 'login' => $response->assertRedirect(route('login')),
        is_string($expected) => $response->assertRedirect(url(substr($expected, strlen('→ ')))),
        default => $response->assertStatus($expected),
    };
})->with(array_keys(ROUTE_MATRIX))->with(MATRIX_ROLES);

/**
 * Registered routes that are in neither the matrix nor the exclusions.
 *
 * @return list<string>
 */
function unmatchedRoutes(): array
{
    $excluded = fn (string $key): bool => collect(array_keys(ROUTE_MATRIX_EXCLUDED))->contains(
        fn (string $pattern) => str_ends_with($pattern, '*') ? str_starts_with($key, substr($pattern, 0, -1)) : $key === $pattern,
    );
    $inMatrix = collect(array_keys(ROUTE_MATRIX))->map(fn (string $key) => preg_replace('/ \(.*\)$/', '', $key))->all();

    return collect(Route::getRoutes()->getRoutes())
        ->map(fn (RouteDefinition $route) => routeKey($route))
        ->unique()
        ->reject(fn (string $key) => in_array($key, $inMatrix, true) || $excluded($key))
        ->values()
        ->all();
}

test('every registered route is in the matrix or excluded with a reason', function () {
    expect(unmatchedRoutes())->toBe([]);
});

test('the matrix and its exclusions describe only routes that exist', function () {
    $registered = collect(Route::getRoutes()->getRoutes())->map(fn (RouteDefinition $route) => routeKey($route))->unique();

    $staleRows = collect(array_keys(ROUTE_MATRIX))
        ->map(fn (string $key) => preg_replace('/ \(.*\)$/', '', $key))
        ->reject(fn (string $key) => $registered->contains($key))
        ->values()->all();

    $staleExclusions = collect(array_keys(ROUTE_MATRIX_EXCLUDED))
        ->reject(fn (string $pattern) => $registered->contains(
            fn (string $key) => str_ends_with($pattern, '*') ? str_starts_with($key, substr($pattern, 0, -1)) : $key === $pattern,
        ))
        ->values()->all();

    expect($staleRows)->toBe([])->and($staleExclusions)->toBe([]);
});

test('the guard catches a route nobody added to the matrix', function () {
    Route::middleware(['web', 'auth'])->get('/barista/forgotten', fn () => 'ok');

    expect(unmatchedRoutes())->toBe(['GET barista/forgotten']);
});
