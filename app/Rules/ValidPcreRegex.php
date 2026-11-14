<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes when the value is a syntactically valid PCRE pattern, so a broken
 * Grep alert regex is rejected at save time rather than failing silently on
 * every evaluation. Used for Alert::grep_regex. Patterns are stored bare (no
 * delimiters) — delimit(), used here and by AlertEvaluationService, is the
 * single place that wraps a bare pattern for preg_match(), so validation and
 * evaluation can never disagree on what the stored string means.
 */
class ValidPcreRegex implements ValidationRule
{
    public static function delimit(string $pattern): string
    {
        return '/'.str_replace('/', '\/', $pattern).'/';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $previousHandler = set_error_handler(fn () => true);
        $result = @preg_match(self::delimit((string) $value), '');
        restore_error_handler();

        if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
            $fail('The :attribute is not a valid regular expression.');
        }
    }
}
