<?php

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Http\Middleware\SetLocale;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MenuItem;
use Database\Seeders\MenuDescriptionsSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
 * #64: bilingual drink descriptions (description_en + description_zh).
 */

/**
 * Chinese for HTTP requests (SetLocale reads the session) and for code run in the test itself.
 */
function describeInChinese(): void
{
    session([SetLocale::SESSION_KEY => 'zh_TW']);
    app()->setLocale('zh_TW');
}

function seededDescriptionDrink(string $name): MenuItem
{
    return MenuItem::query()->where('name_en', $name)->firstOrFail();
}

/**
 * Both descriptions of every drink, keyed by name_en.
 *
 * @return array<string, array{0: string|null, 1: string|null}>
 */
function allDescriptions(): array
{
    return MenuItem::query()->orderBy('id')->get()
        ->mapWithKeys(fn (MenuItem $item): array => [$item->name_en => [$item->description_en, $item->description_zh]])
        ->all();
}

test('menu_items has description_en and description_zh, and no description', function () {
    expect(Schema::hasColumns('menu_items', ['description_en', 'description_zh']))->toBeTrue()
        ->and(Schema::hasColumn('menu_items', 'description'))->toBeFalse();
});

describe('seeding', function () {
    test('a fresh seed gives all 30 drinks both descriptions, and no audit entries', function () {
        $this->seed(MenuSeeder::class);

        $descriptions = allDescriptions();

        expect($descriptions)->toHaveCount(30)
            ->and(collect($descriptions)->every(fn (array $pair): bool => filled($pair[0]) && filled($pair[1])))->toBeTrue()
            ->and(seededDescriptionDrink('Classic Milk Tea')->only(['description_en', 'description_zh']))->toBe([
                'description_en' => 'Our house black tea, brewed strong and shaken with creamy milk. The one that started it all.',
                'description_zh' => '招牌紅茶濃濃沖泡，加入香醇奶香搖勻，最經典的一杯。',
            ])
            ->and(seededDescriptionDrink('Taro Purée Fresh Milk')->description_zh)->toBe('手工芋泥搭配鮮奶，綿密濃厚，喝得到芋頭顆粒。')
            ->and(AuditLog::count())->toBe(0);
    });

    test('every seeded description matches a seeded drink in its category and fits the 300-character limit', function () {
        $this->seed(MenuSeeder::class);

        foreach (MenuDescriptionsSeeder::DESCRIPTIONS as $slug => $drinks) {
            $category = Category::query()->where('slug', $slug)->firstOrFail();

            foreach ($drinks as $name => [$en, $zh]) {
                expect($category->menuItems()->where('name_en', $name)->exists())->toBeTrue("{$slug}: {$name}")
                    ->and(mb_strlen($en))->toBeLessThanOrEqual(300)
                    ->and(mb_strlen($zh))->toBeLessThanOrEqual(300);
            }
        }

        expect(collect(MenuDescriptionsSeeder::DESCRIPTIONS)->flatten(1))->toHaveCount(30);
    });

    test('the back-fill fills an existing menu with empty descriptions, and a second run changes nothing', function () {
        $this->seed(MenuSeeder::class);
        $seeded = allDescriptions();

        // The menu as it was before #64: every description empty (null, '' or blank).
        DB::table('menu_items')->update(['description_en' => null, 'description_zh' => null]);
        DB::table('menu_items')->where('name_en', 'Taro Milk Tea')->update(['description_en' => '', 'description_zh' => '   ']);

        $this->seed(MenuDescriptionsSeeder::class);

        expect(allDescriptions())->toBe($seeded)
            ->and(AuditLog::count())->toBe(30);

        $updatedAt = DB::table('menu_items')->pluck('updated_at', 'id')->all();

        $this->seed(MenuDescriptionsSeeder::class);

        expect(allDescriptions())->toBe($seeded)
            ->and(AuditLog::count())->toBe(30)
            ->and(DB::table('menu_items')->pluck('updated_at', 'id')->all())->toBe($updatedAt);
    });

    test('the back-fill is recorded in the audit log with no user: the system made the change', function () {
        $this->seed(MenuSeeder::class);
        DB::table('menu_items')->where('name_en', 'Hojicha Milk Tea')->update(['description_en' => null, 'description_zh' => null]);

        $this->seed(MenuDescriptionsSeeder::class);

        $drink = seededDescriptionDrink('Hojicha Milk Tea');

        expect(AuditLog::query()->sole()->only(['user_id', 'event', 'auditable_id', 'old_values', 'new_values']))->toBe([
            'user_id' => null,
            'event' => 'updated',
            'auditable_id' => $drink->id,
            'old_values' => ['description_en' => null, 'description_zh' => null],
            'new_values' => [
                'description_en' => 'Roasted Japanese green tea with milk. Toasty, smooth and low in bitterness.',
                'description_zh' => '日式焙茶加奶，焙火香氣溫潤，幾乎不苦澀。',
            ],
        ]);
    });

    test('text written in admin survives the back-fill; only the empty column is filled', function () {
        $this->seed(MenuSeeder::class);

        $drink = seededDescriptionDrink('Mango Green Tea');
        $drink->update(['description_en' => 'Our summer favourite.', 'description_zh' => null]);

        $this->seed(MenuDescriptionsSeeder::class);

        expect($drink->fresh()->only(['description_en', 'description_zh']))->toBe([
            'description_en' => 'Our summer favourite.',
            'description_zh' => '熟芒果搭配綠茶，還有芒果果肉，香甜多汁。',
        ]);
    });

    test('re-running MenuSeeder keeps admin descriptions', function () {
        $this->seed(MenuSeeder::class);
        seededDescriptionDrink('Classic Milk Tea')->update(['description_en' => 'Edited in admin.', 'description_zh' => '後台改過。']);

        $this->seed(MenuSeeder::class);

        expect(seededDescriptionDrink('Classic Milk Tea')->only(['description_en', 'description_zh']))
            ->toBe(['description_en' => 'Edited in admin.', 'description_zh' => '後台改過。'])
            ->and(MenuItem::count())->toBe(30);
    });

    test('a drink renamed in admin is skipped, not re-created or overwritten', function () {
        $this->seed(MenuSeeder::class);
        DB::table('menu_items')->update(['description_en' => null, 'description_zh' => null]);
        $renamed = seededDescriptionDrink('Yuzu Green Tea');
        $renamed->update(['name_en' => 'Yuzu Citrus Green Tea']);

        $this->seed(MenuDescriptionsSeeder::class);

        expect($renamed->fresh()->only(['description_en', 'description_zh']))->toBe(['description_en' => null, 'description_zh' => null])
            ->and(MenuItem::count())->toBe(30)
            ->and(MenuItem::query()->whereNotNull('description_en')->count())->toBe(29);
    });
});

describe('localizedDescription()', function () {
    test('it follows the language, falling back from Chinese to English only', function (?string $en, ?string $zh, string $locale, ?string $expected) {
        $item = MenuItem::factory()->make(['description_en' => $en, 'description_zh' => $zh]);
        app()->setLocale($locale);

        expect($item->localizedDescription())->toBe($expected);
    })->with([
        'English' => ['Smooth.', '順口。', 'en', 'Smooth.'],
        'Chinese' => ['Smooth.', '順口。', 'zh_TW', '順口。'],
        'Chinese empty: English' => ['Smooth.', null, 'zh_TW', 'Smooth.'],
        'Chinese blank: English' => ['Smooth.', '  ', 'zh_TW', 'Smooth.'],
        'English empty: nothing, never Chinese' => [null, '順口。', 'en', null],
        'English blank: nothing' => [' ', '順口。', 'en', null],
        'both empty' => [null, '', 'zh_TW', null],
    ]);
});

describe('drink builder', function () {
    beforeEach(function () {
        $this->drink = MenuItem::factory()->create([
            'name_en' => 'Described Tea',
            'description_en' => 'Bright and floral.',
            'description_zh' => '清香花韻。',
        ]);
    });

    test('it shows the English description in English', function () {
        $this->get(route('menu.show', $this->drink))
            ->assertOk()
            ->assertSeeHtml('data-test="description"')
            ->assertSee('Bright and floral.')
            ->assertDontSee('清香花韻。');
    });

    test('it shows the Chinese description in Chinese', function () {
        describeInChinese();

        $this->get(route('menu.show', $this->drink))
            ->assertOk()
            ->assertSee('清香花韻。')
            ->assertDontSee('Bright and floral.');
    });

    test('in Chinese it falls back to English when the Chinese one is empty', function () {
        $this->drink->update(['description_zh' => null]);
        describeInChinese();

        $this->get(route('menu.show', $this->drink))->assertSee('Bright and floral.');
    });

    test('in English it shows nothing when the English one is empty', function () {
        $this->drink->update(['description_en' => null]);

        $this->get(route('menu.show', $this->drink))
            ->assertOk()
            ->assertDontSeeHtml('data-test="description"')
            ->assertDontSee('清香花韻。');
    });

    test('a description is escaped, never rendered as HTML', function () {
        $this->drink->update(['description_en' => '<b>Bold</b> & <script>alert(1)</script>']);

        $html = $this->get(route('menu.show', $this->drink))->assertOk()->getContent();

        expect($html)->toContain('&lt;b&gt;Bold&lt;/b&gt; &amp; &lt;script&gt;alert(1)&lt;/script&gt;')
            ->not->toContain('<script>alert(1)</script>');
    });

    test('menu cards don\'t show descriptions', function () {
        $this->get(route('home'))->assertSee('Described Tea')->assertDontSee('Bright and floral.');
    });
});

describe('admin', function () {
    beforeEach(function () {
        $this->seed(RoleSeeder::class);
        $this->drink = MenuItem::factory()->create(['name_en' => 'Admin Tea', 'description_en' => null, 'description_zh' => null]);
        $this->actingAs($this->manager = userWithRole('manager'));
    });

    test('a manager saves both descriptions, and the audit log records old → new', function () {
        Livewire::test(EditMenuItem::class, ['record' => $this->drink->getRouteKey()])
            ->assertSchemaComponentExists('description_en')
            ->assertSchemaComponentExists('description_zh')
            ->fillForm(['description_en' => 'Toasty and smooth.', 'description_zh' => '焙香滑順。'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->drink->fresh()->only(['description_en', 'description_zh']))
            ->toBe(['description_en' => 'Toasty and smooth.', 'description_zh' => '焙香滑順。'])
            ->and(AuditLog::query()->sole()->only(['user_id', 'old_values', 'new_values']))->toBe([
                'user_id' => $this->manager->id,
                'old_values' => ['description_en' => null, 'description_zh' => null],
                'new_values' => ['description_en' => 'Toasty and smooth.', 'description_zh' => '焙香滑順。'],
            ]);

        $this->actingAs(userWithRole('admin'));

        Livewire::test(ListAuditLogs::class)
            ->assertSee('Description (EN): — → Toasty and smooth.')
            ->assertSee('Description (ZH): — → 焙香滑順。');
    });

    test('more than 300 characters is rejected on the server', function (string $field, string $text) {
        Livewire::test(EditMenuItem::class, ['record' => $this->drink->getRouteKey()])
            ->fillForm([$field => $text])
            ->call('save')
            ->assertHasFormErrors([$field => 'max']);

        expect($this->drink->fresh()->{$field})->toBeNull();
    })->with([
        'English, 301 letters' => ['description_en', str_repeat('a', 301)],
        'Chinese, 301 characters' => ['description_zh', str_repeat('茶', 301)],
    ]);

    test('exactly 300 Chinese characters is allowed (characters, not bytes)', function () {
        Livewire::test(EditMenuItem::class, ['record' => $this->drink->getRouteKey()])
            ->fillForm(['description_zh' => str_repeat('茶', 300)])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(mb_strlen($this->drink->fresh()->description_zh))->toBe(300);
    });
});
