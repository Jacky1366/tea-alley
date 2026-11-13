<?php

/*
 * Money must be integer cents end to end. These files may not use division,
 * float literals, float casts, or rounding functions. Comments are ignored, so
 * docblocks can still explain the maths.
 */

const PRICING_FILES = [
    'app/Services/PricingService.php',
    'app/Services/TaxCalculator.php',
    'app/Services/PriceBreakdown.php',
    'app/Services/PriceLine.php',
];

const FORBIDDEN_FUNCTIONS = ['round', 'floor', 'ceil', 'floatval', 'doubleval', 'fdiv', 'fmod', 'intval', 'number_format'];

/**
 * Float-related tokens in PHP source, as "line N: description".
 *
 * @return list<string>
 */
function floatViolations(string $source): array
{
    $violations = [];

    foreach (PhpToken::tokenize($source) as $token) {
        $problem = match (true) {
            $token->is([T_COMMENT, T_DOC_COMMENT]) => null,
            $token->text === '/' => 'division operator /',
            $token->is(T_DIV_EQUAL) => 'division operator /=',
            $token->is(T_DNUMBER) => "float literal {$token->text}",
            $token->is(T_DOUBLE_CAST) => "float cast {$token->text}",
            $token->is(T_STRING) && in_array(strtolower($token->text), FORBIDDEN_FUNCTIONS, true) => "function {$token->text}()",
            default => null,
        };

        if ($problem !== null) {
            $violations[] = "line {$token->line}: {$problem}";
        }
    }

    return $violations;
}

test('pricing code uses no float maths', function (string $file) {
    $source = file_get_contents(base_path($file));

    expect($source)->not->toBeFalse()
        ->and(floatViolations((string) $source))->toBe([]);
})->with(PRICING_FILES);

test('the scanner catches every kind of float maths, but not comments', function () {
    $sample = <<<'PHP'
        <?php
        // a / b in a comment is fine
        /** so is round() in a docblock */
        $a = $b / 2;
        $a /= 2;
        $c = 0.5;
        $d = (float) $e;
        $f = round($g);
        $h = intdiv($i, 2);
        PHP;

    expect(floatViolations($sample))->toBe([
        'line 4: division operator /',
        'line 5: division operator /=',
        'line 6: float literal 0.5',
        'line 7: float cast (float)',
        'line 8: function round()',
    ]);
});
