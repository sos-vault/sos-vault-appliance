<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes when the value is a Slack incoming-webhook URL. This is an SSRF
 * guard, not just a format check: an outbound webhook URL is exactly the
 * kind of user-supplied field that gets pointed at internal infrastructure
 * if left unrestricted, so only Slack's own domain is accepted.
 */
class SlackWebhookUrl implements ValidationRule
{
    private const PREFIX = 'https://hooks.slack.com/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! str_starts_with($value, self::PREFIX) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            $fail('The :attribute must be a Slack incoming-webhook URL (https://hooks.slack.com/...).');
        }
    }
}
