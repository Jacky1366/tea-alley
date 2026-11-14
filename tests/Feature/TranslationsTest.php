<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\Support\TranslationKeyScanner;

/**
 * The places allowed to call __() with something other than a literal. Each builds its
 * key from a fixed set that is checked separately below.
 */
const ALLOWED_DYNAMIC = [
    'app/Support/LocalTime.php',        // __('formats.'.$pattern): the formats.* keys
    'app/Support/OptionSummary.php',    // __($key) for formats.* separators
    'resources/views/partials/head.blade.php', // __($title): page titles, scanned from #[Title]
];

/**
 * @return array<string, string>
 */
function zhJson(): array
{
    return json_decode(File::get(lang_path('zh_TW.json')), true, flags: JSON_THROW_ON_ERROR);
}

test('every translation key the customer side uses has a Traditional Chinese translation', function () {
    $json = zhJson();
    $missing = [];

    foreach (TranslationKeyScanner::scan()['keys'] as $key => $locations) {
        $translated = TranslationKeyScanner::isGroupKey($key)
            ? Lang::has($key, 'zh_TW', false)
            : isset($json[$key]) && trim($json[$key]) !== '';

        if (! $translated) {
            $missing[] = "{$key}  ({$locations[0]})";
        }
    }

    expect($missing)->toBe([]);
});

test('the scan finds the keys it should', function () {
    $keys = array_keys(TranslationKeyScanner::scan()['keys']);

    // A view (__), Livewire #[Title], a service, an enum, trans_choice in a component.
    expect($keys)->toContain('Add to cart', 'Customize your drink', ':option is sold out.', 'Payment pending',
        '{0} Cart, empty|{1} Cart, :count drink|[2,*] Cart, :count drinks');
});

test('only the allowed places call __() with a non-literal key', function () {
    $unexpected = collect(TranslationKeyScanner::scan()['dynamic'])
        ->reject(fn (string $location) => in_array(explode(':', $location)[0], ALLOWED_DYNAMIC, true))
        ->values()
        ->all();

    expect($unexpected)->toBe([]);
});

test('translations keep the same :placeholders as their keys', function () {
    $mismatched = [];

    foreach (zhJson() as $key => $value) {
        if (TranslationKeyScanner::placeholders($key) !== TranslationKeyScanner::placeholders($value)) {
            $mismatched[] = $key;
        }
    }

    expect($mismatched)->toBe([]);
});

test('every zh_TW.json key is still used, or is a vendor pagination string', function () {
    $used = array_keys(TranslationKeyScanner::scan()['keys']);
    $vendor = ['Showing', 'to', 'of', 'results', 'Go to page :page', 'Pagination Navigation'];

    expect(array_values(array_diff(array_keys(zhJson()), $used, $vendor)))->toBe([]);
});

test('Laravel\'s validation, auth, password, and pagination files are fully translated', function (string $group) {
    // Empty arrays (like validation's 'attributes' => []) have nothing to translate.
    $english = array_filter(
        Arr::dot(require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$group}.php")),
        fn ($text) => $text !== [],
    );
    $chinese = Arr::dot(require lang_path("zh_TW/{$group}.php"));

    $missing = array_values(array_diff(array_keys($english), array_keys($chinese)));
    $placeholderMismatch = collect($english)
        ->filter(fn ($text, $key) => is_string($text) && isset($chinese[$key])
            && TranslationKeyScanner::placeholders($text) !== TranslationKeyScanner::placeholders($chinese[$key]))
        ->keys()
        ->all();

    expect($missing)->toBe([])->and($placeholderMismatch)->toBe([]);
})->with(['validation', 'auth', 'passwords', 'pagination']);

test('our formats file has the same keys in both languages', function () {
    expect(array_keys(require lang_path('zh_TW/formats.php')))->toBe(array_keys(require lang_path('en/formats.php')));
});

test('the Chinese translations use Traditional characters only', function () {
    // Common Simplified-only characters (each differs from its Traditional form).
    // Only characters that exist in Simplified alone; shared ones like 杯, 冰, 糖, 台 are fine.
    $simplified = '这们个时订单购车结账选项价码设删关说开为对会请应该还没过号务机电级读写网页图样两种发点击户录'
        .'盖鲜绿红乌龙饮钟后确认显隐复让试换语简体湾见进运营业币税总计备变动处状态预约尽';
    $pattern = '/['.$simplified.']/u';

    $texts = [
        'zh_TW.json' => implode("\n", zhJson()),
        ...collect(File::files(lang_path('zh_TW')))
            ->mapWithKeys(fn ($file) => [$file->getFilename() => implode("\n", Arr::dot(require $file->getPathname()))])
            ->all(),
    ];

    $found = collect($texts)
        ->map(fn (string $text) => preg_match_all($pattern, $text, $m) ? implode('', array_unique($m[0])) : '')
        ->filter()
        ->all();

    expect($found)->toBe([]);
});
