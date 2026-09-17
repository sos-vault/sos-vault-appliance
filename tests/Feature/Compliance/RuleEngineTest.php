<?php

use App\Services\Compliance\RuleEngine;

function baseRule(array $overrides = []): array
{
    return array_merge([
        'id' => 'test-rule',
        'category' => 'Test',
        'title' => 'Test rule',
        'description' => 'A test rule.',
        'severity' => 'MEDIUM',
        'remediation' => 'Do the thing.',
        'reference_url' => null,
    ], $overrides);
}

it('resolves a rule to pass when the check closure returns pass', function () {
    $rule = baseRule(['check' => fn (array $facts) => 'pass']);

    $results = (new RuleEngine)->evaluate([$rule], []);

    expect($results)->toHaveCount(1)
        ->and($results[0]['rule_id'])->toBe('test-rule')
        ->and($results[0]['status'])->toBe('pass');
});

it('resolves a rule to fail when the check closure returns fail', function () {
    $rule = baseRule(['check' => fn (array $facts) => 'fail']);

    $results = (new RuleEngine)->evaluate([$rule], []);

    expect($results[0]['status'])->toBe('fail');
});

it('accepts an array return from check with evidence and matched_path', function () {
    $rule = baseRule([
        'check' => fn (array $facts) => [
            'status' => 'fail',
            'evidence' => ['found' => 'yes'],
            'matched_path' => 'etc/ssh/sshd_config',
        ],
    ]);

    $results = (new RuleEngine)->evaluate([$rule], []);

    expect($results[0]['status'])->toBe('fail')
        ->and($results[0]['evidence'])->toBe(['found' => 'yes'])
        ->and($results[0]['matched_path'])->toBe('etc/ssh/sshd_config');
});

it('marks a rule not_applicable when applicable returns false, without calling check', function () {
    $called = false;
    $rule = baseRule([
        'applicable' => fn (array $facts) => false,
        'check' => function (array $facts) use (&$called) {
            $called = true;

            return 'pass';
        },
    ]);

    $results = (new RuleEngine)->evaluate([$rule], []);

    expect($results[0]['status'])->toBe('not_applicable')
        ->and($called)->toBeFalse();
});

it('calls check when applicable returns true', function () {
    $rule = baseRule([
        'applicable' => fn (array $facts) => true,
        'check' => fn (array $facts) => 'pass',
    ]);

    $results = (new RuleEngine)->evaluate([$rule], []);

    expect($results[0]['status'])->toBe('pass');
});

it('carries category, title, description, severity, remediation and reference_url through untouched', function () {
    $rule = baseRule([
        'category' => 'SSH Hardening',
        'title' => 'Some title',
        'description' => 'Some description',
        'severity' => 'HIGH',
        'remediation' => 'Fix it',
        'reference_url' => 'https://example.test/ref',
        'check' => fn (array $facts) => 'pass',
    ]);

    $results = (new RuleEngine)->evaluate([$rule], []);

    expect($results[0])->toMatchArray([
        'category' => 'SSH Hardening',
        'title' => 'Some title',
        'description' => 'Some description',
        'severity' => 'HIGH',
        'remediation' => 'Fix it',
        'reference_url' => 'https://example.test/ref',
    ]);
});

// The most important behavior in this sprint: one bad rule must never abort
// the batch. A throwing check is recorded as an 'error' finding with the
// exception message in evidence, and every other rule in the batch still
// evaluates normally.
it('does not abort the batch when a check throws — records an error finding and keeps evaluating the rest', function () {
    $throwing = baseRule([
        'id' => 'throwing-rule',
        'check' => function (array $facts) {
            throw new RuntimeException('boom: unexpected fact shape');
        },
    ]);

    $before = baseRule(['id' => 'before-rule', 'check' => fn (array $facts) => 'pass']);
    $after = baseRule(['id' => 'after-rule', 'check' => fn (array $facts) => 'fail']);

    $results = (new RuleEngine)->evaluate([$before, $throwing, $after], []);

    expect($results)->toHaveCount(3)
        ->and($results[0]['rule_id'])->toBe('before-rule')
        ->and($results[0]['status'])->toBe('pass')
        ->and($results[1]['rule_id'])->toBe('throwing-rule')
        ->and($results[1]['status'])->toBe('error')
        ->and($results[1]['evidence'])->toHaveKey('exception')
        ->and($results[1]['evidence']['exception'])->toContain('boom: unexpected fact shape')
        ->and($results[2]['rule_id'])->toBe('after-rule')
        ->and($results[2]['status'])->toBe('fail');
});

it('records an error finding when check throws any Throwable, not just Exception subclasses', function () {
    $rule = baseRule([
        'check' => function (array $facts) {
            throw new TypeError('bad type');
        },
    ]);

    $results = (new RuleEngine)->evaluate([$rule], []);

    expect($results[0]['status'])->toBe('error')
        ->and($results[0]['evidence']['exception'])->toBe('bad type');
});

it('evaluates a large batch where multiple rules throw, without losing any results', function () {
    $rules = [];
    for ($i = 0; $i < 20; $i++) {
        $shouldThrow = $i % 3 === 0;
        $rules[] = baseRule([
            'id' => "rule-{$i}",
            'check' => function (array $facts) use ($shouldThrow) {
                if ($shouldThrow) {
                    throw new RuntimeException('failure');
                }

                return 'pass';
            },
        ]);
    }

    $results = (new RuleEngine)->evaluate($rules, []);

    expect($results)->toHaveCount(20);

    $errorCount = collect($results)->where('status', 'error')->count();
    $passCount = collect($results)->where('status', 'pass')->count();

    expect($errorCount)->toBe(7)
        ->and($passCount)->toBe(13);
});
