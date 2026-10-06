<?php

use App\Rules\SlackWebhookUrl;
use App\Rules\ValidPcreRegex;
use Illuminate\Contracts\Validation\ValidationRule;

function ruleFails(ValidationRule $rule, $value): bool
{
    $failed = false;
    $rule->validate('field', $value, function () use (&$failed) {
        $failed = true;
    });

    return $failed;
}

it('accepts a syntactically valid bare regex pattern', function (string $pattern) {
    expect(ruleFails(new ValidPcreRegex, $pattern))->toBeFalse();
})->with([
    'PermitRootLogin\s+no',
    '^default via',
    '\bcrond\b',
    'foo/bar',
]);

it('rejects a syntactically invalid regex pattern', function (string $pattern) {
    expect(ruleFails(new ValidPcreRegex, $pattern))->toBeTrue();
})->with([
    '(unclosed',
    '[a-',
    '*invalid',
]);

it('delimit() escapes embedded slashes so a path-like pattern stays valid', function () {
    $delimited = ValidPcreRegex::delimit('foo/bar');

    expect(@preg_match($delimited, 'foo/bar'))->toBe(1);
});

it('accepts a Slack incoming-webhook URL', function () {
    expect(ruleFails(new SlackWebhookUrl, 'https://hooks.slack.com/services/T00/B00/xyz'))->toBeFalse();
});

it('rejects a non-Slack URL as an SSRF guard', function (string $url) {
    expect(ruleFails(new SlackWebhookUrl, $url))->toBeTrue();
})->with([
    'http://hooks.slack.com/services/T00/B00/xyz', // not https
    'https://evil.example.com/hooks.slack.com/',    // spoofed host
    'https://internal-metadata.local/latest/',       // SSRF target
    'not-a-url',
]);
