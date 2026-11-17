<?php

use Symfony\Component\Finder\Finder;

/*
 * Blade directives (@js, @json, @class, ...) are not compiled inside the attributes of a
 * component tag (<flux:...>, <x-...>, <livewire:...>): the tag compiler turns attributes
 * into PHP strings before directives are compiled, so "@js($x)" reaches the browser as
 * text. Flux's Blaze sometimes hides this by folding a component to plain HTML at compile
 * time, but not when a prop such as :variant is dynamic, so a page can break when an
 * unrelated attribute changes. Pass values with a bound attribute instead:
 *
 *     :wire:click="'selectCategory('.\Illuminate\Support\Js::from($tab->slug).')'"
 */

/**
 * Each component tag's opening text, from "<flux:x" up to its closing ">" (quote-aware,
 * so a ">" inside an attribute value such as $this->foo doesn't end the tag).
 *
 * @return list<array{line: int, tag: string}>
 */
function componentTags(string $source): array
{
    preg_match_all('/<(?:flux:|x-|livewire:)[\w.:-]+/', $source, $matches, PREG_OFFSET_CAPTURE);

    $tags = [];

    foreach ($matches[0] as [$open, $offset]) {
        $quote = null;

        for ($i = $offset + strlen($open), $length = strlen($source); $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                break;
            }
        }

        $tags[] = [
            'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
            'tag' => substr($source, $offset, $i - $offset + 1),
        ];
    }

    return $tags;
}

/**
 * "file:line <tag" for every component tag with a directive call in its attributes.
 *
 * @return list<string>
 */
function directivesInComponentTags(string $directory): array
{
    $found = [];

    foreach (Finder::create()->files()->in($directory)->name('*.blade.php') as $file) {
        foreach (componentTags($file->getContents()) as ['line' => $line, 'tag' => $tag]) {
            // "@name(" not preceded by a word character, so e-mail addresses don't count.
            if (preg_match('/(?<![\w.@])@[a-z]+\s*\(/i', $tag)) {
                $found[] = $file->getRelativePathname().':'.$line.' '.strtok($tag, " \n");
            }
        }
    }

    return $found;
}

test('no view puts a Blade directive inside a component tag\'s attributes', function () {
    expect(directivesInComponentTags(resource_path('views')))->toBe([]);
});

test('the check finds a directive in a multi-line component tag, past a -> in an earlier attribute', function () {
    $directory = sys_get_temp_dir().'/blade-directive-check-'.getmypid();
    is_dir($directory) || mkdir($directory);
    file_put_contents($directory.'/bad.blade.php', <<<'BLADE'
        <div x-data="{ a: @js($a) }"></div>
        <flux:button
            :variant="$this->active?->is($tab) ? 'primary' : 'ghost'"
            wire:click="select(@js($tab->slug))"
        />
        <flux:button :wire:click="'select('.\Illuminate\Support\Js::from($tab->slug).')'" />
        <flux:text>mail teaalley.demo@gmail.com</flux:text>
        BLADE);

    try {
        expect(directivesInComponentTags($directory))->toBe(['bad.blade.php:2 <flux:button']);
    } finally {
        unlink($directory.'/bad.blade.php');
        rmdir($directory);
    }
});
