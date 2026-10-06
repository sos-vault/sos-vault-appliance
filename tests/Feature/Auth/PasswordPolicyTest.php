<?php

/**
 * PasswordPolicy service — regex/min-length switching by complexity mode.
 *
 * Covers:
 *  - Default mode: historic regex shape (12 chars, 2 each upper/lower/digit/sign).
 *  - Relaxed mode: 9 chars, 1 each.
 *  - Custom mode: counts come from settings.
 *  - Mode falls back to default for unknown values.
 */

use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Wave\Setting;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::forget('wave_settings'));

function setComplexity(string $mode, array $custom = []): void
{
    Setting::updateOrCreate(
        ['key' => 'auth.password_complexity'],
        ['value' => $mode, 'display_name' => 'auth.password_complexity', 'type' => 'text', 'order' => 0]
    );
    foreach ($custom as $k => $v) {
        Setting::updateOrCreate(
            ['key' => 'auth.password_custom_'.$k],
            ['value' => (string) $v, 'display_name' => 'auth.password_custom_'.$k, 'type' => 'text', 'order' => 0]
        );
    }
    Cache::forget('wave_settings');
}

it('returns the historic regex and min length in default mode', function () {
    setComplexity(PasswordPolicy::MODE_DEFAULT);

    expect(PasswordPolicy::mode())->toBe('default');
    expect(PasswordPolicy::minLength())->toBe(12);

    $rx = PasswordPolicy::regex();
    // Strong password — matches.
    expect((bool) preg_match($rx, 'AaBb12!@xyzZ'))->toBeTrue();
    // Too short — no match.
    expect((bool) preg_match($rx, 'Aa1!Aa1!Aa'))->toBeFalse();
    // Only one digit — no match.
    expect((bool) preg_match($rx, 'AaBbCc1!@xyz'))->toBeFalse();
});

it('uses the relaxed profile (9 chars / 1 each) when configured', function () {
    setComplexity(PasswordPolicy::MODE_RELAXED);

    expect(PasswordPolicy::minLength())->toBe(9);
    $rx = PasswordPolicy::regex();
    expect((bool) preg_match($rx, 'Abcdef1!x'))->toBeTrue();
    expect((bool) preg_match($rx, 'abcdefghi'))->toBeFalse();   // no upper/digit/sign
    expect((bool) preg_match($rx, 'Abcd1!'))->toBeFalse();      // too short
});

it('builds the regex from custom settings when in custom mode', function () {
    setComplexity(PasswordPolicy::MODE_CUSTOM, [
        'min_length' => 16,
        'min_digits' => 3,
        'min_upper' => 1,
        'min_lower' => 1,
        'min_signs' => 0,
    ]);

    expect(PasswordPolicy::minLength())->toBe(16);
    $rx = PasswordPolicy::regex();
    // 16 chars, 1 upper, 1 lower, 3 digits, no signs required → matches.
    expect((bool) preg_match($rx, 'Aaaaaaaaaaaa1234'))->toBeTrue();
    // Only 2 digits → no match.
    expect((bool) preg_match($rx, 'Aaaaaaaaaaaaa12x'))->toBeFalse();
    // 15 chars → no match.
    expect((bool) preg_match($rx, 'Aaaaaaaaaaa1234'))->toBeFalse();
});

it('falls back to default when the complexity setting is unknown', function () {
    setComplexity('not-a-real-mode');

    expect(PasswordPolicy::mode())->toBe('default');
    expect(PasswordPolicy::minLength())->toBe(12);
});

it('exposes a requirements bundle for the front-end helper', function () {
    setComplexity(PasswordPolicy::MODE_DEFAULT);

    $req = PasswordPolicy::requirements();
    expect($req)->toHaveKeys(['min_length', 'min_digits', 'min_upper', 'min_lower', 'min_signs', 'allowed_signs', 'regex']);
    expect($req['min_length'])->toBe(12);
    expect($req['min_upper'])->toBe(2);
    expect($req['allowed_signs'])->toContain('!')->toContain('@');
});
