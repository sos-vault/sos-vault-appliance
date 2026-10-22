<?php

namespace App\Services;

class PasswordPolicy
{
    public const MODE_DEFAULT = 'default';

    public const MODE_RELAXED = 'relaxed';

    public const MODE_CUSTOM = 'custom';

    private const DEFAULT_PROFILE = [
        'min_length' => 12,
        'min_digits' => 2,
        'min_upper' => 2,
        'min_lower' => 2,
        'min_signs' => 2,
    ];

    private const RELAXED_PROFILE = [
        'min_length' => 9,
        'min_digits' => 1,
        'min_upper' => 1,
        'min_lower' => 1,
        'min_signs' => 1,
    ];

    /**
     * Return the active policy as an associative array of integer counts.
     *
     * @return array{min_length:int,min_digits:int,min_upper:int,min_lower:int,min_signs:int}
     */
    public static function profile(): array
    {
        $mode = (string) (setting('auth.password_complexity', self::MODE_DEFAULT) ?: self::MODE_DEFAULT);

        if ($mode === self::MODE_RELAXED) {
            return self::RELAXED_PROFILE;
        }

        if ($mode === self::MODE_CUSTOM) {
            return [
                'min_length' => max(1, (int) setting('auth.password_custom_min_length', 12)),
                'min_digits' => max(0, (int) setting('auth.password_custom_min_digits', 2)),
                'min_upper' => max(0, (int) setting('auth.password_custom_min_upper', 2)),
                'min_lower' => max(0, (int) setting('auth.password_custom_min_lower', 2)),
                'min_signs' => max(0, (int) setting('auth.password_custom_min_signs', 2)),
            ];
        }

        return self::DEFAULT_PROFILE;
    }

    public static function mode(): string
    {
        $mode = (string) (setting('auth.password_complexity', self::MODE_DEFAULT) ?: self::MODE_DEFAULT);

        return in_array($mode, [self::MODE_DEFAULT, self::MODE_RELAXED, self::MODE_CUSTOM], true)
            ? $mode
            : self::MODE_DEFAULT;
    }

    /**
     * Build the validation regex from the active profile.
     *
     * Backwards compatible with the historic regex when in default mode:
     *   /^(?=(?:.*[A-Z]){2,})(?=(?:.*[a-z]){2,})(?=(?:.*\d){2,})(?=(?:.*[^A-Za-z0-9]){2,}).{12,}$/
     */
    public static function regex(): string
    {
        $p = self::profile();

        return self::regexFor($p);
    }

    /**
     * @param  array{min_length:int,min_digits:int,min_upper:int,min_lower:int,min_signs:int}  $p
     */
    public static function regexFor(array $p): string
    {
        $parts = '';
        if ($p['min_upper'] > 0) {
            $parts .= '(?=(?:.*[A-Z]){'.$p['min_upper'].',})';
        }
        if ($p['min_lower'] > 0) {
            $parts .= '(?=(?:.*[a-z]){'.$p['min_lower'].',})';
        }
        if ($p['min_digits'] > 0) {
            $parts .= '(?=(?:.*\d){'.$p['min_digits'].',})';
        }
        if ($p['min_signs'] > 0) {
            $parts .= '(?=(?:.*[^A-Za-z0-9]){'.$p['min_signs'].',})';
        }

        return '/^'.$parts.'.{'.$p['min_length'].',}$/';
    }

    public static function minLength(): int
    {
        return self::profile()['min_length'];
    }

    /**
     * Sample list of allowed sign characters shown in the UI helper.
     * The actual regex accepts any non-alphanumeric character ([^A-Za-z0-9]);
     * this is just an example set so users know what counts.
     */
    public static function allowedSignsExample(): string
    {
        return '! @ # $ % ^ & * ( ) _ + - = [ ] { } | ; : , . < > ?';
    }

    /**
     * Requirements bundle for the live front-end helper.
     *
     * @return array{
     *   min_length:int,
     *   min_digits:int,
     *   min_upper:int,
     *   min_lower:int,
     *   min_signs:int,
     *   allowed_signs:string,
     *   regex:string
     * }
     */
    public static function requirements(): array
    {
        $p = self::profile();

        return $p + [
            'allowed_signs' => self::allowedSignsExample(),
            'regex' => self::regexFor($p),
        ];
    }
}
