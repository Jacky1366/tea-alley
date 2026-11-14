<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Finds the translation keys the customer side uses, for the missing-translation test.
 *
 * Scans every Blade view and the app code that produces customer-facing text (not the
 * Filament admin, which stays English). A key counts when the whole first argument of
 * __(), trans(), trans_choice() or @lang() is one string literal, or it is the literal
 * in #[Title('...')] (page titles are translated in partials/head). Anything else, like
 * __($label) or __('formats.'.$pattern), is reported as dynamic so it can't slip past.
 */
final class TranslationKeyScanner
{
    private const CALL = '/(?<![\w>$:])(?:__|trans|trans_choice|@lang)\(\s*/';

    private const LITERAL = '/\G(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")\s*(?=[,)])/s';

    private const TITLE = '/#\[Title\(\s*(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")\s*\)\]/';

    /**
     * @return list<string> absolute paths
     */
    public static function files(): array
    {
        $views = collect(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'));

        $code = collect(['Actions', 'Concerns', 'Enums', 'Http', 'Listeners', 'Models', 'Services', 'Support', 'View'])
            ->filter(fn (string $dir): bool => is_dir(app_path($dir)))
            ->flatMap(fn (string $dir) => File::allFiles(app_path($dir)));

        return $views->merge($code)
            ->map(fn (SplFileInfo $file): string => $file->getPathname())
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{keys: array<string, list<string>>, dynamic: list<string>}
     *                                                                         keys: key => locations ("path:line"); dynamic: locations
     */
    public static function scan(): array
    {
        $keys = [];
        $dynamic = [];

        foreach (self::files() as $path) {
            $source = File::get($path);
            $relative = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);

            preg_match_all(self::CALL, $source, $calls, PREG_OFFSET_CAPTURE);

            foreach ($calls[0] as [$match, $offset]) {
                $start = $offset + strlen($match);
                $location = $relative.':'.(substr_count($source, "\n", 0, $offset) + 1);

                if (preg_match(self::LITERAL, $source, $literal, 0, $start) === 1) {
                    $keys[self::unquote($literal[1])][] = $location;
                } else {
                    $dynamic[] = $location;
                }
            }

            preg_match_all(self::TITLE, $source, $titles, PREG_OFFSET_CAPTURE);

            foreach ($titles[1] as [$literal, $offset]) {
                $keys[self::unquote($literal)][] = $relative.':'.(substr_count($source, "\n", 0, $offset) + 1);
            }
        }

        ksort($keys);

        return ['keys' => $keys, 'dynamic' => $dynamic];
    }

    /**
     * A group key ("validation.required", "formats.time") lives in lang/{locale}/{group}.php,
     * not in the JSON file.
     */
    public static function isGroupKey(string $key): bool
    {
        return preg_match('/^([a-z_-]+)\.[A-Za-z0-9_.-]+$/', $key, $m) === 1
            && is_file(lang_path('en/'.$m[1].'.php')) || preg_match('/^(validation|auth|passwords|pagination)\./', $key) === 1;
    }

    /**
     * The :placeholders in a string, sorted, for comparing a key with its translation.
     *
     * @return list<string>
     */
    public static function placeholders(string $text): array
    {
        preg_match_all('/:([A-Za-z_]+)/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    private static function unquote(string $literal): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body)
            : stripcslashes($body);
    }
}
