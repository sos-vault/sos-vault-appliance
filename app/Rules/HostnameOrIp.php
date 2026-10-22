<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes when the value is a valid IPv4/IPv6 address or a DNS hostname/FQDN.
 * Used for the SIEM destination host in Manage Settings. Empty values pass
 * (pair with 'nullable') so the field can be cleared.
 */
class HostnameOrIp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $isIp = filter_var($value, FILTER_VALIDATE_IP) !== false;
        $isHost = preg_match('/^(?=.{1,253}$)([a-zA-Z0-9](-?[a-zA-Z0-9])*)(\.[a-zA-Z0-9](-?[a-zA-Z0-9])*)*$/', (string) $value) === 1;

        if (! $isIp && ! $isHost) {
            $fail('Enter a valid FQDN or IP address.');
        }
    }
}
