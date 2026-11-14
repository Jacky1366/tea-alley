<?php

use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\User;
use App\Services\Cart;
use App\Services\ModifierValidator;
use App\Services\ValidatedDrink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Fixture: "Cart Tea" ($6.00) with
 *   Size      required single-select: Medium (default, +0), Large +50
 *   Toppings  optional, up to 3: Pearls +75, Pudding +100
 * and "Cart Soda" ($5.00, carbonated, no options).
 * Ordering is open all day; the clock is Wed Nov 18, 2026 12:00 in Vancouver.
 */
beforeEach(function () {
    setStoreHours(array_fill(0, 7, ['00:00:00', '23:59:00']));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'America/Vancouver'));

    $this->tea = MenuItem::factory()->create(['name_en' => 'Cart Tea', 'name_zh' => '購物車茶', 'price_cents' => 600]);
    $this->size = ModifierGroup::factory()->create(['name_en' => 'Size', 'is_required' => true, 'max_select' => 1]);
    $this->toppings = ModifierGroup::factory()->create(['name_en' => 'Toppings', 'max_select' => 3]);
    $this->tea->modifierGroups()->attach([$this->size->id => ['sort_order' => 1], $this->toppings->id => ['sort_order' => 2]]);

    // Explicit sort_order: the factory's is random, which would make display order flaky.
    $this->medium = ModifierOption::factory()->for($this->size)->create(['name_en' => 'Medium', 'price_delta_cents' => 0, 'is_default' => true, 'sort_order' => 1]);
    $this->large = ModifierOption::factory()->for($this->size)->create(['name_en' => 'Large', 'price_delta_cents' => 50, 'sort_order' => 2]);
    $this->pearls = ModifierOption::factory()->for($this->toppings)->create(['name_en' => 'Pearls', 'price_delta_cents' => 75, 'sort_order' => 1]);
    $this->pudding = ModifierOption::factory()->for($this->toppings)->create(['name_en' => 'Pudding', 'price_delta_cents' => 100, 'sort_order' => 2]);

    $this->soda = MenuItem::factory()->carbonated()->create(['name_en' => 'Cart Soda', 'price_cents' => 500]);

    $this->cart = app(Cart::class);
});

/**
 * @param  list<ModifierOption>  $options
 */
function validDrink(MenuItem $item, array $options = [], int $quantity = 1): ValidatedDrink
{
    return app(ModifierValidator::class)->validate(
        MenuItem::findOrFail($item->id),
        array_map(fn (ModifierOption $o) => $o->id, $options),
        $quantity,
    );
}

/**
 * Run a cart call that should fail and return the error keys.
 *
 * @return list<string>
 */
function cartErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    throw new RuntimeException('Expected a ValidationException.');
}

function cartPage(): Testable
{
    return Livewire::test('pages::cart');
}

/**
 * Whether the checkout control in the HTML is disabled (a disabled <button>, not a link).
 */
function checkoutDisabled(string $html): bool
{
    expect(preg_match('/<(a|button)\b[^>]*data-test="checkout-button"[^>]*>/', $html, $match))->toBe(1);

    return $match[1] === 'button' && (bool) preg_match('/\sdisabled(\s|=|>)/', $match[0]);
}

describe('storage', function () {
    test('the session holds only ids and quantities', function () {
        $this->cart->add(validDrink($this->tea, [$this->pudding, $this->large, $this->pearls], 2));
        $this->cart->add(validDrink($this->soda));

        $ids = [$this->large->id, $this->pearls->id, $this->pudding->id];
        sort($ids);

        expect(session('cart'))->toBe([
            $this->tea->id.':'.implode(',', $ids) => ['menu_item_id' => $this->tea->id, 'option_ids' => $ids, 'quantity' => 2],
            $this->soda->id.':' => ['menu_item_id' => $this->soda->id, 'option_ids' => [], 'quantity' => 1],
        ]);
    });

    test('the same drink with the same options merges into one line', function () {
        $this->cart->add(validDrink($this->tea, [$this->medium, $this->pearls], 2));
        $this->cart->add(validDrink($this->tea, [$this->pearls, $this->medium], 3));

        expect(session('cart'))->toHaveCount(1)
            ->and($this->cart->count())->toBe(5);
    });

    test('the same drink with different options is a separate line', function () {
        $this->cart->add(validDrink($this->tea, [$this->medium]));
        $this->cart->add(validDrink($this->tea, [$this->large]));
        $this->cart->add(validDrink($this->tea, [$this->medium, $this->pearls]));

        expect(session('cart'))->toHaveCount(3)
            ->and($this->cart->count())->toBe(3);
    });
});

describe('limits', function () {
    test('one line can hold at most 10 of the same drink', function () {
        $this->cart->add(validDrink($this->soda, quantity: 6));

        expect(cartErrors(fn () => $this->cart->add(validDrink($this->soda, quantity: 5))))->toBe(['quantity'])
            ->and($this->cart->count())->toBe(6);

        $this->cart->add(validDrink($this->soda, quantity: 4)); // exactly 10 is fine

        expect($this->cart->count())->toBe(10);
    });

    test('the cart can hold at most 20 drinks', function () {
        $this->cart->add(validDrink($this->soda, quantity: 10));
        $this->cart->add(validDrink($this->tea, [$this->medium], 10));

        expect(cartErrors(fn () => $this->cart->add(validDrink($this->tea, [$this->large]))))->toBe(['cart'])
            ->and($this->cart->count())->toBe(20)
            ->and(session('cart'))->toHaveCount(2);
    });

    test('setQuantity accepts 1 to 10', function () {
        $this->cart->add(validDrink($this->soda, quantity: 3));
        $key = $this->soda->id.':';

        $this->cart->setQuantity($key, 1);
        expect($this->cart->count())->toBe(1);

        $this->cart->setQuantity($key, 10);
        expect($this->cart->count())->toBe(10);
    });

    test('setQuantity rejects quantities outside 1 to 10 and leaves the cart unchanged', function (int $quantity) {
        $this->cart->add(validDrink($this->soda, quantity: 3));
        $before = session('cart');

        expect(cartErrors(fn () => $this->cart->setQuantity($this->soda->id.':', $quantity)))->toBe(['quantity'])
            ->and(session('cart'))->toBe($before);
    })->with([0, 11, -1]);

    test('setQuantity also enforces the 20-drink cart limit', function () {
        // Three lines, because two lines of at most 10 can never pass 20.
        $this->cart->add(validDrink($this->soda, quantity: 8));
        $this->cart->add(validDrink($this->tea, [$this->medium], 5));
        $this->cart->add(validDrink($this->tea, [$this->large], 5)); // 18 drinks
        $before = session('cart');

        // Each quantity is within 1-10, but the cart would hold 21.
        expect(cartErrors(fn () => $this->cart->setQuantity($this->tea->id.':'.$this->medium->id, 8)))->toBe(['cart'])
            ->and(session('cart'))->toBe($before);

        $this->cart->setQuantity($this->tea->id.':'.$this->medium->id, 7); // exactly 20 is fine
        expect($this->cart->count())->toBe(20);
    });

    test('setQuantity and remove ignore unknown keys', function () {
        $this->cart->add(validDrink($this->soda, quantity: 2));
        $before = session('cart');

        $this->cart->setQuantity('999:1,2', 5);
        $this->cart->remove('999:1,2');

        expect(session('cart'))->toBe($before);
    });

    test('remove and clear', function () {
        $this->cart->add(validDrink($this->soda));
        $this->cart->add(validDrink($this->tea, [$this->medium], 2));

        $this->cart->remove($this->soda->id.':');
        expect($this->cart->count())->toBe(2);

        $this->cart->clear();
        expect($this->cart->count())->toBe(0)
            ->and(session('cart'))->toBeNull();
    });
});

describe('re-validation', function () {
    test('a line that no longer validates becomes invalid with a readable reason', function (Closure $break, string $reason) {
        $this->cart->add(validDrink($this->tea, [$this->medium, $this->pearls], 2));
        $this->cart->add(validDrink($this->soda));

        $break($this);

        $contents = $this->cart->contents();

        expect($contents->valid)->toHaveCount(1)
            ->and($contents->valid[0]->item->is($this->soda))->toBeTrue()
            ->and($contents->invalid)->toHaveCount(1)
            ->and($contents->invalid[0]->quantity)->toBe(2)
            ->and($contents->invalid[0]->reasons)->toContain($reason);
    })->with([
        'drink sold out' => [fn ($t) => $t->tea->update(['is_available' => false]), 'This drink is sold out.'],
        'option sold out' => [fn ($t) => $t->pearls->update(['is_available' => false]), 'Pearls is sold out.'],
        'category inactive' => [fn ($t) => $t->tea->category->update(['is_active' => false]), 'This drink is not available.'],
        'drink deleted' => [fn ($t) => $t->tea->delete(), 'This drink is no longer on the menu.'],
        'option deleted' => [fn ($t) => $t->pearls->delete(), 'One of the chosen options does not exist.'],
        'group removed from the drink' => [fn ($t) => $t->tea->modifierGroups()->detach($t->toppings), 'One of the chosen options is not offered for this drink.'],
        'group became more restrictive' => [fn ($t) => $t->toppings->update(['min_select' => 2]), 'Choose at least 2 options for Toppings.'],
    ]);

    test('invalid lines keep the drink name when the drink still exists', function () {
        $this->cart->add(validDrink($this->tea, [$this->medium]));
        $this->tea->update(['is_available' => false]);

        expect($this->cart->contents()->invalid[0]->name)->toBe('Cart Tea');
    });

    test('a price change shows up in the cart', function () {
        $this->cart->add(validDrink($this->soda, quantity: 2));

        $this->soda->update(['price_cents' => 550]);

        cartPage()->assertSee('$11.00')->assertDontSee('$10.00');
    });

    test('damaged session entries are reported, skipped by count, and removable', function () {
        $this->cart->add(validDrink($this->soda, quantity: 2));
        session()->put('cart', [
            ...session('cart'),
            'bogus' => ['menu_item_id' => $this->tea->id, 'option_ids' => [], 'quantity' => 1], // key doesn't match ids
            '7:1' => 'not an array',
        ]);

        $contents = $this->cart->contents();

        expect($contents->valid)->toHaveCount(1)
            ->and($contents->invalid)->toHaveCount(2)
            ->and($contents->invalid[0]->reasons)->toBe(['This item could not be loaded.'])
            ->and($this->cart->count())->toBe(2);

        $this->cart->remove('bogus');
        $this->cart->remove('7:1');

        expect($this->cart->contents()->invalid)->toBe([]);
    });

    test('contents() uses the same number of queries for 1 line or many', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->cart->contents();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $this->cart->add(validDrink($this->tea, [$this->medium]));
        $one = $queries();

        $this->cart->add(validDrink($this->tea, [$this->large, $this->pearls]));
        $this->cart->add(validDrink($this->tea, [$this->medium, $this->pudding]));
        $this->cart->add(validDrink($this->soda));
        $many = $queries();

        expect($many)->toBe($one);
    });
});

describe('cart page', function () {
    test('guests can open the cart; an empty cart says so and links to the menu', function () {
        $response = $this->get(route('cart'))
            ->assertOk()
            ->assertSee('data-test="cart-empty"', escape: false)
            ->assertSee('Your cart is empty')
            ->assertSee('href="'.route('home').'"', escape: false);

        expect($response->getContent())->not->toContain('data-test="checkout-button"');
    });

    test('lines show name, options, unit price, quantity, line total, and the subtotal', function () {
        $this->cart->add(validDrink($this->tea, [$this->large, $this->pearls, $this->pudding], 2)); // 600+50+75+100 = 825 x 2
        $this->cart->add(validDrink($this->soda));                                                   // 500

        $html = $this->get(route('cart'))
            ->assertOk()
            ->assertSee('Cart Tea')
            ->assertDontSee('購物車茶')
            ->assertSee('Size: Large · Toppings: Pearls, Pudding')
            ->assertSee('$8.25 each')
            ->assertSee('$16.50')
            ->assertSee('Cart Soda')
            ->assertSee('$5.00 each')
            ->assertSeeInOrder(['Subtotal', '$21.50', 'Taxes calculated at checkout'])
            ->getContent();

        expect(checkoutDisabled($html))->toBeFalse()
            ->and($html)->toContain('href="'.url('/checkout').'"');
    });

    test('invalid lines are listed separately, are not in the subtotal, and block checkout', function () {
        $this->cart->add(validDrink($this->tea, [$this->medium], 2)); // 1200, will be sold out
        $this->cart->add(validDrink($this->soda));                     // 500
        $this->tea->update(['is_available' => false]);

        $html = $this->get(route('cart'))
            ->assertOk()
            ->assertSee('data-test="invalid-lines"', escape: false)
            ->assertSee('Cart Tea × 2')
            ->assertSee('This drink is sold out.')
            ->assertSeeInOrder(['Subtotal', '$5.00'])
            ->getContent();

        expect(checkoutDisabled($html))->toBeTrue();
    });

    test('invalid lines can be removed, which re-enables checkout', function () {
        $this->cart->add(validDrink($this->tea, [$this->medium]));
        $this->cart->add(validDrink($this->soda));
        $this->tea->update(['is_available' => false]);

        cartPage()
            ->call('remove', $this->tea->id.':'.$this->medium->id)
            ->assertDontSee('data-test="invalid-lines"', escape: false)
            ->assertSee('$5.00');

        expect(checkoutDisabled($this->get(route('cart'))->getContent()))->toBeFalse();
    });

    test('while ordering is closed, checkout is disabled but quantities can change and lines can be removed', function () {
        $this->cart->add(validDrink($this->soda, quantity: 2));
        $this->cart->add(validDrink($this->tea, [$this->medium]));
        setStoreHours([3 => ['11:00:00', '21:00:00']]);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 22:00:00', 'America/Vancouver'));

        $html = $this->get(route('cart'))->assertSee('data-test="ordering-closed"', escape: false)->getContent();
        expect(checkoutDisabled($html))->toBeTrue();

        cartPage()
            ->call('setQuantity', $this->soda->id.':', 3)
            ->assertHasNoErrors()
            ->call('remove', $this->tea->id.':'.$this->medium->id);

        expect($this->cart->count())->toBe(3);
    });

    test('the stepper changes quantity and the page refreshes the badge', function () {
        $this->cart->add(validDrink($this->soda, quantity: 2));

        cartPage()
            ->call('setQuantity', $this->soda->id.':', 4)
            ->assertDispatched('cart-updated')
            ->assertSee('$20.00');

        expect($this->cart->count())->toBe(4);
    });
});

describe('tampered cart requests', function () {
    test('are rejected and leave the cart unchanged', function (string $method, Closure $args, ?string $errorKey) {
        $this->cart->add(validDrink($this->soda, quantity: 2));
        $this->cart->add(validDrink($this->tea, [$this->medium], 10));
        $this->cart->add(validDrink($this->tea, [$this->large], 5)); // 17 drinks
        $before = session('cart');

        $component = cartPage()->call($method, ...$args($this));

        $errorKey === null ? $component->assertHasNoErrors() : $component->assertHasErrors([$errorKey]);

        expect(session('cart'))->toBe($before);
    })->with([
        'unknown key, setQuantity' => ['setQuantity', fn ($t) => ['999:1', 2], null],
        'unknown key, remove' => ['remove', fn ($t) => ['999:1'], null],
        'quantity 0' => ['setQuantity', fn ($t) => [$t->soda->id.':', 0], 'quantity'],
        'quantity 11' => ['setQuantity', fn ($t) => [$t->soda->id.':', 11], 'quantity'],
        'quantity pushing the cart over 20' => ['setQuantity', fn ($t) => [$t->soda->id.':', 6], 'cart'], // 6 + 10 + 5 = 21
    ]);
});

describe('header badge', function () {
    test('shows the drink count on public pages', function () {
        $this->cart->add(validDrink($this->soda, quantity: 3));

        foreach ([route('home'), route('menu.show', $this->tea), route('cart')] as $url) {
            $this->get($url)
                ->assertSee('data-test="cart-link"', escape: false)
                ->assertSeeInOrder(['data-test="cart-count"', '>3<'], escape: false);
        }
    });

    test('hides the number when the cart is empty', function () {
        Livewire::test('cart-badge')
            ->assertSet('count', 0)
            ->assertDontSee('data-test="cart-count"', escape: false);
    });

    test('updates on the cart-updated event after adding, changing quantity, and removing', function () {
        $badge = Livewire::test('cart-badge')->assertSet('count', 0);

        $this->cart->add(validDrink($this->soda, quantity: 2));
        $badge->dispatch('cart-updated')->assertSet('count', 2)->assertSee('2');

        $this->cart->setQuantity($this->soda->id.':', 5);
        $badge->dispatch('cart-updated')->assertSet('count', 5);

        $this->cart->remove($this->soda->id.':');
        $badge->dispatch('cart-updated')->assertSet('count', 0);
    });
});

describe('sessions', function () {
    test('a guest cart survives logging in, even though the session id is regenerated', function () {
        Livewire::test('pages::drink', ['item' => $this->soda])->set('quantity', 2)->call('addToCart');
        $cartBefore = session('cart');
        $sessionIdBefore = session()->getId();

        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
        expect(session()->getId())->not->toBe($sessionIdBefore)
            ->and(session('cart'))->toBe($cartBefore);

        $this->get(route('cart'))->assertSee('Cart Soda')->assertSee('$10.00');
    });

    test('a guest cart survives registering', function () {
        $this->cart->add(validDrink($this->soda, quantity: 2));
        $sessionIdBefore = session()->getId();

        $this->post(route('register.store'), [
            'name' => 'New Customer',
            'email' => 'new-customer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
        expect(session()->getId())->not->toBe($sessionIdBefore)
            ->and($this->cart->count())->toBe(2);
    });

    test('logging out clears the cart', function () {
        $this->actingAs(User::factory()->create());
        $this->cart->add(validDrink($this->soda, quantity: 2));

        $this->post(route('logout'));

        $this->assertGuest();
        expect(session('cart'))->toBeNull()
            ->and(app(Cart::class)->count())->toBe(0);
    });
});
