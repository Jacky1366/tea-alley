<?php

namespace App\Enums;

/**
 * The languages the customer side can be shown in. English is the default; anything
 * unknown (old sessions, tampered requests, bad database values) is treated as English.
 */
enum Locale: string
{
    case English = 'en';
    case TraditionalChinese = 'zh_TW';

    public static function default(): self
    {
        return self::English;
    }

    /**
     * A supported locale, or the default for anything else.
     */
    public static function fromValue(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::default()) : self::default();
    }

    public static function isSupported(mixed $value): bool
    {
        return is_string($value) && self::tryFrom($value) !== null;
    }

    /**
     * The language the header switch offers from this one. With two languages it's the other one.
     */
    public function other(): self
    {
        return $this === self::English ? self::TraditionalChinese : self::English;
    }

    /**
     * The switch button's label, written in its own language so either reader can find it.
     */
    public function switchLabel(): string
    {
        return match ($this) {
            self::English => 'EN',
            self::TraditionalChinese => '中文',
        };
    }

    /**
     * The value for <html lang>.
     */
    public function htmlLang(): string
    {
        return str_replace('_', '-', $this->value);
    }

    public static function current(): self
    {
        return self::fromValue(app()->getLocale());
    }
}
