<?php

use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Services\ModifierValidator;
use App\Services\PriceBreakdown;
use App\Services\PriceLine;
use App\Services\PricingService;
use App\Services\TaxCalculator;
use App\Services\ValidatedDrink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Needs the app for config(); only the zero-query test touches the database.
uses(TestCase::class, RefreshDatabase::class);

/**
 * An in-memory ValidatedDrink (no database): a menu item plus options with the given deltas.
 *
 * @param  list<int>  $deltas
 */
function pricedDrink(int $priceCents, string $taxCategory, array $deltas = [], int $quantity = 1): ValidatedDrink
{
    static $nextId = 1;

    $item = (new MenuItem)->forceFill(['id' => $nextId++, 'price_cents' => $priceCents, 'tax_category' => $taxCategory]);

    $options = collect($deltas)->values()->map(
        fn (int $delta, int $i) => (new ModifierOption)->forceFill(['id' => 1000 + $i, 'price_delta_cents' => $delta]),
    );

    return new ValidatedDrink($item, $options->pluck('id')->all(), $quantity, $options);
}

/**
 * @param  list<array{0: int, 1: string, 2?: list<int>, 3?: int}>  $specs  [price, tax category, deltas, quantity]
 * @return list<ValidatedDrink>
 */
function pricedDrinks(array $specs): array
{
    return array_map(fn (array $spec) => pricedDrink($spec[0], $spec[1], $spec[2] ?? [], $spec[3] ?? 1), $specs);
}

/**
 * The invariants every breakdown must satisfy.
 */
function assertBreakdownInvariants(PriceBreakdown $b): void
{
    expect($b->total_cents)->toBe($b->subtotal_cents - $b->discount_cents + $b->gst_cents + $b->pst_cents)
        ->and($b->standard_discount_cents + $b->carbonated_discount_cents)->toBe($b->discount_cents)
        ->and($b->standard_discount_cents)->toBeGreaterThanOrEqual(0)
        ->and($b->carbonated_discount_cents)->toBeGreaterThanOrEqual(0)
        ->and($b->discount_cents)->toBeLessThanOrEqual($b->subtotal_cents)
        ->and($b->subtotal_cents)->toBe(array_sum(array_map(fn (PriceLine $l) => $l->line_total_cents, $b->lines)));
}

const STD = MenuItem::TAX_STANDARD;
const CARB = MenuItem::TAX_CARBONATED;

describe('price', function () {
    test('calculates every amount', function (array $specs, int $discount, array $expected) {
        $b = app(PricingService::class)->price(pricedDrinks($specs), $discount);

        expect([
            'subtotal' => $b->subtotal_cents,
            'discount' => $b->discount_cents,
            'gst' => $b->gst_cents,
            'pst' => $b->pst_cents,
            'total' => $b->total_cents,
            'standard_discount' => $b->standard_discount_cents,
            'carbonated_discount' => $b->carbonated_discount_cents,
        ])->toBe($expected);

        assertBreakdownInvariants($b);
    })->with([
        // 595 + 50 + 75 = 720 x 2 = 1440. GST 72.00.
        'standard with toppings, quantity 2' => [
            [[595, STD, [50, 75], 2]], 0,
            ['subtotal' => 1440, 'discount' => 0, 'gst' => 72, 'pst' => 0, 'total' => 1512, 'standard_discount' => 0, 'carbonated_discount' => 0],
        ],
        // 650 + 75 = 725. GST 36.25 -> 36, PST 50.75 -> 51.
        'carbonated only (GST + PST)' => [
            [[650, CARB, [75], 1]], 0,
            ['subtotal' => 725, 'discount' => 0, 'gst' => 36, 'pst' => 51, 'total' => 812, 'standard_discount' => 0, 'carbonated_discount' => 0],
        ],
        // GST on 2165 = 108.25 -> 108; PST on 725 = 50.75 -> 51.
        'standard and carbonated mixed' => [
            [[595, STD, [50, 75], 2], [650, CARB, [75], 1]], 0,
            ['subtotal' => 2165, 'discount' => 0, 'gst' => 108, 'pst' => 51, 'total' => 2324, 'standard_discount' => 0, 'carbonated_discount' => 0],
        ],
        // The worked example from the plan: carbonated share 100.46 -> 100, GST 93.25 -> 93, PST 43.75 -> 44.
        'mixed with a discount (worked example)' => [
            [[595, STD, [50, 75], 2], [650, CARB, [75], 1]], 300,
            ['subtotal' => 2165, 'discount' => 300, 'gst' => 93, 'pst' => 44, 'total' => 2002, 'standard_discount' => 200, 'carbonated_discount' => 100],
        ],
        'no drinks' => [
            [], 0,
            ['subtotal' => 0, 'discount' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0, 'standard_discount' => 0, 'carbonated_discount' => 0],
        ],
        'no drinks, with a discount' => [
            [], 500,
            ['subtotal' => 0, 'discount' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0, 'standard_discount' => 0, 'carbonated_discount' => 0],
        ],
        // 10 x 5% = 0.5 -> rounds up to 1.
        'GST exactly half a cent rounds up' => [
            [[10, STD]], 0,
            ['subtotal' => 10, 'discount' => 0, 'gst' => 1, 'pst' => 0, 'total' => 11, 'standard_discount' => 0, 'carbonated_discount' => 0],
        ],
        // 9 x 5% = 0.45 -> 0.
        'GST just under half a cent rounds down' => [
            [[9, STD]], 0,
            ['subtotal' => 9, 'discount' => 0, 'gst' => 0, 'pst' => 0, 'total' => 9, 'standard_discount' => 0, 'carbonated_discount' => 0],
        ],
        // 50 x 5% = 2.5 -> 3; 50 x 7% = 3.5 -> 4.
        'GST and PST both exactly half a cent' => [
            [[50, CARB]], 0,
            ['subtotal' => 50, 'discount' => 0, 'gst' => 3, 'pst' => 4, 'total' => 57, 'standard_discount' => 0, 'carbonated_discount' => 0],
        ],
        // Taxed once per order, not per line: 3 lines of 10 = 30 -> GST 1.5 -> 2 (per line would be 1 + 1 + 1 = 3).
        'tax is rounded once per order, not per line' => [
            [[10, STD], [10, STD], [10, STD]], 0,
            ['subtotal' => 30, 'discount' => 0, 'gst' => 2, 'pst' => 0, 'total' => 32, 'standard_discount' => 0, 'carbonated_discount' => 0],
        ],
        // GST on 750 = 37.5 -> 38.
        'discount less than the subtotal' => [
            [[1000, STD]], 250,
            ['subtotal' => 1000, 'discount' => 250, 'gst' => 38, 'pst' => 0, 'total' => 788, 'standard_discount' => 250, 'carbonated_discount' => 0],
        ],
        'discount equal to the subtotal' => [
            [[1000, STD]], 1000,
            ['subtotal' => 1000, 'discount' => 1000, 'gst' => 0, 'pst' => 0, 'total' => 0, 'standard_discount' => 1000, 'carbonated_discount' => 0],
        ],
        'discount greater than the subtotal is capped at the subtotal' => [
            [[1000, STD]], 5000,
            ['subtotal' => 1000, 'discount' => 1000, 'gst' => 0, 'pst' => 0, 'total' => 0, 'standard_discount' => 1000, 'carbonated_discount' => 0],
        ],
        // Carbonated share: 2165 x 725 / 2165 = 725 exactly.
        'mixed, discount equal to the subtotal' => [
            [[595, STD, [50, 75], 2], [650, CARB, [75], 1]], 2165,
            ['subtotal' => 2165, 'discount' => 2165, 'gst' => 0, 'pst' => 0, 'total' => 0, 'standard_discount' => 1440, 'carbonated_discount' => 725],
        ],
        'mixed, discount greater than the subtotal' => [
            [[595, STD, [50, 75], 2], [650, CARB, [75], 1]], 9999,
            ['subtotal' => 2165, 'discount' => 2165, 'gst' => 0, 'pst' => 0, 'total' => 0, 'standard_discount' => 1440, 'carbonated_discount' => 725],
        ],
        // Carbonated share: 1 x 2 / 4 = 0.5 -> 1. GST on 3 = 0.15 -> 0; PST on 2 - 1 = 1 -> 0.07 -> 0.
        'discount split exactly half a cent rounds up' => [
            [[2, STD], [2, CARB]], 1,
            ['subtotal' => 4, 'discount' => 1, 'gst' => 0, 'pst' => 0, 'total' => 3, 'standard_discount' => 0, 'carbonated_discount' => 1],
        ],
        // Carbonated share: 100 x 300 / 1300 = 23.08 -> 23. GST on 1200 = 60. PST on 277 = 19.39 -> 19.
        'carbonated share rounds down below half a cent' => [
            [[1000, STD], [300, CARB]], 100,
            ['subtotal' => 1300, 'discount' => 100, 'gst' => 60, 'pst' => 19, 'total' => 1279, 'standard_discount' => 77, 'carbonated_discount' => 23],
        ],
    ]);

    test('in a mixed order with a discount, PST is only on the carbonated amount after its share of the discount', function () {
        $tax = new TaxCalculator;
        $b = app(PricingService::class)->price(pricedDrinks([[595, STD, [50, 75], 2], [650, CARB, [75], 1]]), 300);

        expect($b->pst_cents)->toBe($tax->pst(725 - 100))
            ->and($b->pst_cents)->not->toBe($tax->pst(725))        // not the undiscounted carbonated amount
            ->and($b->pst_cents)->not->toBe($tax->pst(2165 - 300)); // not the whole discounted order
        assertBreakdownInvariants($b);
    });

    test('lines carry the per-line amounts in the order the drinks were given', function () {
        $milkTea = pricedDrink(595, STD, [50, 75], 2);
        $soda = pricedDrink(650, CARB, [], 3);

        $b = app(PricingService::class)->price([$milkTea, $soda]);

        [$first, $second] = $b->lines;

        expect($b->lines)->toHaveCount(2)
            ->and($first->key)->toBe($milkTea->key())
            ->and($first->menu_item_id)->toBe($milkTea->item->id)
            ->and($first->unit_price_cents)->toBe(720)
            ->and($first->quantity)->toBe(2)
            ->and($first->line_total_cents)->toBe(1440)
            ->and($first->tax_category)->toBe(STD)
            ->and($first->drink)->toBe($milkTea)
            ->and($second->unit_price_cents)->toBe(650)
            ->and($second->line_total_cents)->toBe(1950)
            ->and($second->tax_category)->toBe(CARB)
            ->and($second->drink)->toBe($soda);
    });

    test('identical drinks given twice are both priced (merging is the cart\'s job)', function () {
        $drink = pricedDrink(500, STD);

        $b = app(PricingService::class)->price([$drink, $drink]);

        expect($b->lines)->toHaveCount(2)
            ->and($b->subtotal_cents)->toBe(1000);
    });
});

describe('errors', function () {
    test('a negative discount throws', function () {
        app(PricingService::class)->price(pricedDrinks([[500, STD]]), -1);
    })->throws(InvalidArgumentException::class);

    test('an unknown tax category throws instead of being treated as standard', function () {
        app(PricingService::class)->price(pricedDrinks([[500, STD], [500, 'luxury']]));
    })->throws(UnexpectedValueException::class, 'Unknown tax category [luxury].');
});

describe('configuration', function () {
    test('tax rates come from config', function () {
        config(['tea-alley.gst_bp' => 1000, 'tea-alley.pst_bp' => 0]);

        $b = app(PricingService::class)->price(pricedDrinks([[595, STD, [50, 75], 2], [650, CARB, [75], 1]]), 300);

        // GST 10% of 1865 = 186.5 -> 187. PST 0%.
        expect($b->gst_cents)->toBe(187)
            ->and($b->pst_cents)->toBe(0)
            ->and($b->total_cents)->toBe(2165 - 300 + 187);
        assertBreakdownInvariants($b);
    });

    test('the default rates are 5% GST and 7% PST', function () {
        expect(config('tea-alley.gst_bp'))->toBe(500)
            ->and(config('tea-alley.pst_bp'))->toBe(700);
    });
});

test('price() runs no database queries on drinks from ModifierValidator', function () {
    $item = MenuItem::factory()->create(['price_cents' => 595]);
    $group = ModifierGroup::factory()->create(['max_select' => 3]);
    $item->modifierGroups()->attach($group);
    $pearls = ModifierOption::factory()->for($group)->create(['price_delta_cents' => 75]);
    $pudding = ModifierOption::factory()->for($group)->create(['price_delta_cents' => 100]);

    $drink = app(ModifierValidator::class)->validate(MenuItem::findOrFail($item->id), [$pearls->id, $pudding->id], 2);
    $pricing = app(PricingService::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $b = $pricing->price([$drink], 100);

    expect(DB::getQueryLog())->toBe([])
        ->and($b->subtotal_cents)->toBe((595 + 75 + 100) * 2);

    DB::disableQueryLog();
});
