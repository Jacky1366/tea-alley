<?php

namespace App\Models\Concerns;

use App\Enums\Locale;

/**
 * For models with name_en / name_zh columns (menu data and order snapshots): the name in
 * the current language. A blank Chinese name falls back to English.
 */
trait HasLocalizedName
{
    public function localizedName(): string
    {
        return $this->pickLocalized($this->name_en, $this->name_zh);
    }

    /**
     * The English or Chinese value for the current language, falling back to English.
     */
    protected function pickLocalized(string $en, ?string $zh): string
    {
        if (Locale::current() === Locale::TraditionalChinese && $zh !== null && trim($zh) !== '') {
            return $zh;
        }

        return $en;
    }
}
