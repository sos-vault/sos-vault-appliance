<?php

use App\Models\ComplianceFinding;
use App\Services\Compliance\Rulesets\StigRuleset;

/*
 * Guards against a typo'd stig_rule_version silently shipping wrong text:
 * every ruleVersion StigRuleset::rules() declares for a real OS context must
 * actually exist in the corresponding json/stig/<slug>.json file, and the
 * title/severity sourced from it must match that file's content exactly.
 */
function rhel9StigGroupByVersion(string $ruleVersion): array
{
    $data = json_decode(file_get_contents(base_path('json/stig/red_hat_enterprise_linux_9.json')), true);

    foreach ($data['groups'] as $group) {
        if ($group['ruleVersion'] === $ruleVersion) {
            return $group;
        }
    }

    throw new RuntimeException("fixture bug: {$ruleVersion} not found");
}

it('sources every declared rule_id for the RHEL9 context with a real, existing ruleVersion', function () {
    $rules = StigRuleset::rules(['os_family' => 'rhel', 'os_id' => 'rhel', 'os_version_id' => '9.4']);

    expect($rules)->not->toBeEmpty();

    foreach ($rules as $rule) {
        expect($rule)->toHaveKey('stig_rule_version');

        $group = rhel9StigGroupByVersion($rule['stig_rule_version']);

        expect($rule['title'])->toBe($group['ruleTitle'])
            ->and($rule['description'])->toBe($group['ruleVulnDiscussion'])
            ->and($rule['remediation'])->toBe($group['ruleFixText']);
    }
});

it('maps STIG severity strings onto the ComplianceFinding SEVERITIES vocabulary', function () {
    $rules = StigRuleset::rules(['os_family' => 'rhel', 'os_id' => 'rhel', 'os_version_id' => '9.4']);

    foreach ($rules as $rule) {
        expect(ComplianceFinding::SEVERITIES)->toContain($rule['severity']);
    }
});

it('throws at build time (not silently) when a declared ruleVersion does not exist in the source file', function () {
    $reflection = new ReflectionClass(StigRuleset::class);
    $method = $reflection->getMethod('stigText');
    $method->setAccessible(true);

    expect(fn () => $method->invoke(null, 'red_hat_enterprise_linux_9', 'RHEL-09-NONEXISTENT'))
        ->toThrow(RuntimeException::class);
});

it('has no duplicate rule ids for the RHEL9 context', function () {
    $rules = StigRuleset::rules(['os_family' => 'rhel', 'os_id' => 'rhel', 'os_version_id' => '9.4']);

    $ids = array_column($rules, 'id');

    expect($ids)->toBe(array_unique($ids));
});

it('sources every declared rule_id for the RHEL8 context with a real, existing ruleVersion', function () {
    $rules = StigRuleset::rules(['os_family' => 'rhel', 'os_id' => 'rhel', 'os_version_id' => '8.10']);

    expect($rules)->not->toBeEmpty();

    $data = json_decode(file_get_contents(base_path('json/stig/red_hat_enterprise_linux_8.json')), true);
    $byVersion = [];
    foreach ($data['groups'] as $group) {
        $byVersion[$group['ruleVersion']] = $group;
    }

    foreach ($rules as $rule) {
        expect($byVersion)->toHaveKey($rule['stig_rule_version']);
        expect($rule['title'])->toBe($byVersion[$rule['stig_rule_version']]['ruleTitle']);
    }
});
