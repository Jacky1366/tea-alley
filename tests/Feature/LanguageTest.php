<?php

use App\Enums\Locale;
use App\Enums\PickupType;
use App\Http\Middleware\ForceEnglishLocale;
use App\Http\Middleware\SetLocale;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\User;
use App\Services\Cart;
use App\Services\ModifierValidator;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Fixture: "Lang Milk Tea" / 語言奶茶 ($6.00) with Size 容量 (Medium 中杯 default, Large 大杯 +50)
 * and Toppings 加料 (Pearls 珍珠 +75), in category Milk Tea 奶茶. Ordering is open
 * 11:00-22:00 every day; the clock is Fri Nov 20, 2026 12:00 in Vancouver.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    setStoreHours(array_fill(0, 7, ['11:00:00', '22:00:00']));
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'America/Vancouver'));

    $this->tea = MenuItem::factory()->create(['name_en' => 'Lang Milk Tea', 'name_zh' => '語言奶茶', 'price_cents' => 600]);
    $this->tea->category->update(['name_en' => 'Milk Tea', 'name_zh' => '奶茶']);
    $this->size = ModifierGroup::factory()->create(['name_en' => 'Size', 'name_zh' => '容量', 'is_required' => true, 'max_select' => 1]);
    $this->toppings = ModifierGroup::factory()->create(['name_en' => 'Toppings', 'name_zh' => '加料', 'max_select' => 3]);
    $this->tea->modifierGroups()->attach([$this->size->id => ['sort_order' => 1], $this->toppings->id => ['sort_order' => 2]]);
    $this->medium = ModifierOption::factory()->for($this->size)->create(['name_en' => 'Medium', 'name_zh' => '中杯', 'price_delta_cents' => 0, 'is_default' => true, 'sort_order' => 1]);
    $this->large = ModifierOption::factory()->for($this->size)->create(['name_en' => 'Large', 'name_zh' => '大杯', 'price_delta_cents' => 50, 'sort_order' => 2]);
    $this->pearls = ModifierOption::factory()->for($this->toppings)->create(['name_en' => 'Pearls', 'name_zh' => '珍珠', 'price_delta_cents' => 75, 'sort_order' => 1]);
});

/**
 * Chinese for both HTTP requests (SetLocale reads the session) and Livewire::test(),
 * which doesn't run route middleware.
 */
function inChinese(): void
{
    session([SetLocale::SESSION_KEY => 'zh_TW']);
    app()->setLocale('zh_TW');
}

function langCart(object $t): void
{
    app(Cart::class)->add(app(ModifierValidator::class)->validate(
        MenuItem::findOrFail($t->tea->id), [$t->large->id, $t->pearls->id], 2,
    ));
}

function langOrder(object $t, User $user): Order
{
    langCart($t);
    $order = app(OrderService::class)->place($user, app(Cart::class)->contents(), PickupType::Asap, null, 'lang-1');
    app(Cart::class)->clear();

    return $order;
}

describe('resolving the locale', function () {
    test('English is the default', function () {
        $this->get(route('home'))->assertOk()->assertSee('<html lang="en">', escape: false);

        expect(app()->getLocale())->toBe('en');
    });

    test('the session choice is used, and sets <html lang>', function () {
        inChinese();

        $this->get(route('home'))->assertSee('<html lang="zh-TW">', escape: false)->assertSee('菜單');
    });

    test('an unsupported session value means English, not an error', function (mixed $value) {
        session([SetLocale::SESSION_KEY => $value]);

        $this->get(route('home'))->assertOk()->assertSee('<html lang="en">', escape: false);
    })->with(['fr', 'zh_CN', '', 'ZH_TW', '../../etc/passwd']);

    test('a signed-in user\'s saved language is used when the session has none', function () {
        $user = userWithRole('customer');
        $user->forceFill(['locale' => 'zh_TW'])->save();

        $this->actingAs($user)->get(route('home'))->assertSee('<html lang="zh-TW">', escape: false);

        expect(session(SetLocale::SESSION_KEY))->toBe('zh_TW');
    });

    test('an unsupported saved value means English', function () {
        $user = userWithRole('customer');
        $user->forceFill(['locale' => 'klingon'])->save();

        $this->actingAs($user)->get(route('home'))->assertOk()->assertSee('<html lang="en">', escape: false);
    });

    test('Livewire update requests go through the same middleware', function () {
        $update = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => str_ends_with($route->uri(), '/update') && str_contains($route->uri(), 'livewire'));

        expect($update)->not->toBeNull()
            ->and($update->gatherMiddleware())->toContain('web')
            ->and(app('router')->getMiddlewareGroups()['web'])->toContain(SetLocale::class);
    });
});

describe('switching', function () {
    test('the header shows a CSRF-protected switch to the other language', function () {
        $html = $this->get(route('home'))->getContent();

        expect($html)->toMatch('/<form method="POST" action="'.preg_quote(route('locale.switch'), '/').'" data-test="locale-switch">\s*<input type="hidden" name="_token"/')
            ->toContain('name="locale" value="zh_TW"')
            ->toContain('中文');

        inChinese();

        expect($this->get(route('home'))->getContent())->toContain('name="locale" value="en"')
            ->toMatch('/data-test="locale-switch-button"[^>]*>\s*(<[^>]+>\s*)*EN/');
    });

    test('a guest switch is stored in the session and returns to the same page', function () {
        $page = route('menu.show', $this->tea);
        $this->get($page)->assertOk();

        $this->post(route('locale.switch'), ['locale' => 'zh_TW'])->assertRedirect($page);

        expect(session(SetLocale::SESSION_KEY))->toBe('zh_TW');
        $this->get($page)->assertSee('語言奶茶')->assertDontSee('Lang Milk Tea');
    });

    test('a signed-in switch is saved on the account', function () {
        $user = userWithRole('customer');

        $this->actingAs($user)->post(route('locale.switch'), ['locale' => 'zh_TW'])->assertRedirect();

        expect($user->fresh()->locale)->toBe('zh_TW');
    });

    test('an unsupported value switches to English instead of failing', function (mixed $value) {
        inChinese();
        $user = userWithRole('customer');

        $this->actingAs($user)->post(route('locale.switch'), ['locale' => $value])->assertRedirect();

        expect(session(SetLocale::SESSION_KEY))->toBe('en')
            ->and($user->fresh()->locale)->toBe('en');
    })->with(['fr', '', 'zh_CN', ['array']]);

    test('it never redirects off the site', function () {
        session()->setPreviousUrl('https://evil.example/phish');

        $this->post(route('locale.switch'), ['locale' => 'zh_TW'])->assertRedirect(route('home'));
    });

    test('with no previous page it goes to the menu', function () {
        $this->post(route('locale.switch'), ['locale' => 'zh_TW'])->assertRedirect(route('home'));
    });
});

describe('login, logout, and registration', function () {
    test('a guest who picked Chinese stays in Chinese after logging into an account with no saved language', function () {
        $user = userWithRole('customer');
        $this->post(route('locale.switch'), ['locale' => 'zh_TW']);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->get(route('home'))->assertSee('<html lang="zh-TW">', escape: false);
        expect($user->fresh()->locale)->toBeNull();
    });

    test('a saved language wins over the session at login', function () {
        $user = userWithRole('customer');
        $user->forceFill(['locale' => 'en'])->save();
        $this->post(route('locale.switch'), ['locale' => 'zh_TW']);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->get(route('home'))->assertSee('<html lang="en">', escape: false);
    });

    test('logging out and back in keeps the language', function () {
        $user = userWithRole('customer');
        $this->actingAs($user)->post(route('locale.switch'), ['locale' => 'zh_TW']);

        $this->post(route('logout'));
        $this->assertGuest();
        // A different browser: no session at all.
        session()->flush();
        app()->setLocale('en');
        $this->get(route('home'))->assertSee('<html lang="en">', escape: false);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->get(route('home'))->assertSee('<html lang="zh-TW">', escape: false);
    });

    test('logging out keeps the language but still empties the cart', function () {
        $this->actingAs(userWithRole('customer'));
        langCart($this);
        $this->post(route('locale.switch'), ['locale' => 'zh_TW']);

        $this->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
        expect(session(SetLocale::SESSION_KEY))->toBe('zh_TW')
            ->and(app(Cart::class)->count())->toBe(0);
        $this->get(route('home'))->assertSee('<html lang="zh-TW">', escape: false)->assertSee('登入');
    });

    test('registering saves the current language', function () {
        $this->post(route('locale.switch'), ['locale' => 'zh_TW']);

        $this->post(route('register.store'), [
            'name' => 'Mei', 'email' => 'mei@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ]);

        expect(User::where('email', 'mei@example.com')->value('locale'))->toBe('zh_TW');
    });
});

describe('customer pages in Chinese show only Chinese', function () {
    beforeEach(fn () => inChinese());

    test('menu', function () {
        $this->get(route('home'))
            ->assertSee('語言奶茶')->assertDontSee('Lang Milk Tea')
            ->assertSee('奶茶')->assertDontSee('>Milk Tea<', escape: false)
            ->assertSee('全部')->assertSee('人氣推薦')->assertSee('搜尋飲料…')
            ->assertSee('Tea Alley 茶巷');
    });

    test('drink builder', function () {
        $this->get(route('menu.show', $this->tea))
            ->assertSee('語言奶茶')->assertDontSee('Lang Milk Tea')
            ->assertSeeInOrder(['容量', '中杯', '大杯', '加料', '珍珠'])
            ->assertDontSee('Medium')->assertDontSee('Pearls')
            ->assertSee('加入購物車')->assertSee('必選')->assertSee('選填');
    });

    test('cart', function () {
        langCart($this);

        $this->get(route('cart'))
            ->assertSee('購物車')
            ->assertSee('語言奶茶')->assertDontSee('Lang Milk Tea')
            ->assertSee('容量：大杯 · 加料：珍珠')
            ->assertSee('每杯 $7.25')->assertSee('小計')->assertSee('結帳');
    });

    test('checkout', function () {
        langCart($this);

        Livewire::actingAs(userWithRole('customer'))->test('pages::checkout')
            ->assertSee('結帳')
            ->assertSee('語言奶茶 × 2')->assertDontSee('Lang Milk Tea')
            ->assertSee('盡快取餐')->assertSee('預約取餐')
            ->assertSee('約 中午 12:03 可取餐')
            ->assertSee('GST')->assertSee('PST')->assertSee('總計');
    });

    test('ordering banner dates follow the language', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 23:00:00', 'America/Vancouver'));

        // Closed now; opens Sat 11:00.
        $this->get(route('home'))->assertSee('線上點餐將於 週六 上午 11:00 開放');
    });

    test('the cart badge label is Chinese', function () {
        langCart($this);

        $this->get(route('home'))->assertSee('aria-label="購物車，2 杯"', escape: false);
    });
});

describe('receipts and history switch with the language', function () {
    test('the same order shows English or Chinese snapshot names', function () {
        $user = userWithRole('customer');
        $order = langOrder($this, $user);

        // Renaming the menu afterwards doesn't matter: the snapshot has both names.
        $this->tea->update(['name_zh' => '改名奶茶']);
        $this->actingAs($user);

        $this->get(route('orders.show', $order))
            ->assertSee('Lang Milk Tea × 2')->assertSee('Size: Large')->assertSee('Toppings: Pearls')
            ->assertDontSee('語言奶茶');

        inChinese();

        $this->get(route('orders.show', $order))
            ->assertSee('語言奶茶 × 2')->assertSee('容量：大杯')->assertSee('加料：珍珠')
            ->assertDontSee('Lang Milk Tea')->assertDontSee('改名奶茶')
            ->assertSee('訂單明細')->assertSee('等待付款')
            ->assertSee('下單時間：2026年11月20日 中午 12:00')
            ->assertSee('盡快取餐，約 中午 12:03');
    });

    test('history is translated', function () {
        $user = userWithRole('customer');
        langOrder($this, $user);
        inChinese();

        $this->actingAs($user)->get(route('orders.index'))
            ->assertSee('我的訂單')->assertSee('2 杯')->assertSee('2026年11月20日 中午 12:00');
    });

    test('a blank Chinese snapshot name falls back to English', function () {
        $user = userWithRole('customer');
        $order = langOrder($this, $user);
        $order->items()->update(['name_zh' => '']);
        inChinese();

        $this->actingAs($user)->get(route('orders.show', $order))->assertSee('Lang Milk Tea × 2');
    });
});

describe('search', function () {
    test('finds drinks by English or Chinese in either language', function (string $locale, string $term) {
        session([SetLocale::SESSION_KEY => $locale]);

        $this->get(route('home', ['q' => $term]))->assertSee('data-test="drink-card"', escape: false);
    })->with([
        'English page, English term' => ['en', 'Lang Milk'],
        'English page, Chinese term' => ['en', '語言'],
        'Chinese page, English term' => ['zh_TW', 'Lang Milk'],
        'Chinese page, Chinese term' => ['zh_TW', '語言'],
    ]);
});

describe('error messages are Chinese in Chinese mode', function () {
    beforeEach(fn () => inChinese());

    test('Laravel\'s validation messages', function () {
        $this->post(route('register.store'), ['name' => '', 'email' => 'not-an-email', 'password' => 'x', 'password_confirmation' => 'y'])
            ->assertSessionHasErrors([
                'name' => '請填寫姓名。',
                'email' => '電子郵件必須是有效的電子郵件地址。',
            ]);
    });

    test('Laravel\'s auth messages', function () {
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => '電子郵件或密碼不正確。']);
    });

    test('our drink builder messages', function () {
        $this->pearls->update(['is_available' => false]);

        Livewire::test('pages::drink', ['item' => $this->tea])
            ->set('selected.'.$this->size->id, strval($this->medium->id))
            ->set('selected.'.$this->toppings->id, [strval($this->pearls->id)])
            ->call('addToCart')
            ->assertHasErrors(['selected.'.$this->toppings->id => '珍珠已售完。']);
    });

    test('our cart messages', function () {
        langCart($this);
        $this->pearls->update(['is_available' => false]);

        $this->get(route('cart'))->assertSee('珍珠已售完。')->assertSee('無法點購');
    });

    test('our checkout messages', function () {
        langCart($this);
        $page = Livewire::actingAs(userWithRole('customer'))->test('pages::checkout');
        $this->pearls->update(['is_available' => false]);

        $page->call('placeOrder')->assertHasErrors(['cart' => '購物車中有品項已無法點購，請檢查購物車。']);
    });

    test('our order service messages name the drink and the reason', function () {
        langCart($this);
        // Loaded before Pearls sold out, so OrderService's own re-validation catches it.
        $stale = app(Cart::class)->contents();
        $this->pearls->update(['is_available' => false]);

        try {
            app(OrderService::class)->place(userWithRole('customer'), $stale, PickupType::Asap, null, 'stale');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            expect($e->errors()['cart'])->toBe(['語言奶茶：珍珠已售完。']);
        }
    });
});

describe('admin stays English', function () {
    test('Filament pages render in English for a manager who chose Chinese', function () {
        $manager = userWithRole('manager');
        $manager->forceFill(['locale' => 'zh_TW'])->save();

        $this->actingAs($manager)->get('/admin/menu-items')
            ->assertOk()
            ->assertSee('<html', escape: false)
            ->assertSee('lang="en"', escape: false)
            ->assertSee('Lang Milk Tea');
    });

    test('ForceEnglishLocale is persistent panel middleware, so Livewire re-applies it on updates', function () {
        expect(Livewire::getPersistentMiddleware())
            ->toContain(ForceEnglishLocale::class);
    });
});

test('the Locale enum treats anything unknown as English', function () {
    expect(Locale::fromValue('zh_TW'))->toBe(Locale::TraditionalChinese)
        ->and(Locale::fromValue('zh-TW'))->toBe(Locale::English)
        ->and(Locale::fromValue(null))->toBe(Locale::English)
        ->and(Locale::fromValue(['zh_TW']))->toBe(Locale::English)
        ->and(Locale::English->other())->toBe(Locale::TraditionalChinese)
        ->and(Locale::TraditionalChinese->htmlLang())->toBe('zh-TW');
});
