<?php

namespace App\Models\Concerns;

use App\Enums\Locale;

/**
 * For models with description_en / description_zh columns (#64): the description in the
 * current language. Same rule as names: a blank Chinese description falls back to English,
 * never the other way round, so an English page never shows Chinese text. Null when there's
 * nothing to show.
 */
trait HasLocalizedDescription
{
    public function localizedDescription(): ?string
    {
        $zh = $this->description_zh;

        if (Locale::current() === Locale::TraditionalChinese && is_string($zh) && trim($zh) !== '') {
            return $zh;
        }

        $en = $this->description_en;

        return is_string($en) && trim($en) !== '' ? $en : null;
    }
}
