<?php

use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $user  = User::factory()->create();
    $vault = Vault::factory()->create(['status' => 'OPEN', 'owner' => $user->id]);

    $this->vtools = new VaultTools($user, $vault->id);
});

// ---------------------------------------------------------------------------
// Helpers — realistic log content strings
// ---------------------------------------------------------------------------

/** Classic syslog (pattern D) */
function syslogContent(): string
{
    return implode("\n", [
        'Mar  1 12:00:01 myhost kernel: WARNING: low memory',
        'Mar  1 12:00:02 myhost sshd[1234]: accepted publickey for root',
        'Mar  1 12:00:03 myhost systemd[1]: starting NetworkManager',
        'Mar  1 12:00:04 myhost cron[567]: session opened for user root',
        'Mar  1 12:00:05 myhost sudo[890]: root ran /usr/bin/ls',
    ]);
}

/** ISO date + SEVERITY: message (pattern A) */
function isoSeverityContent(): string
{
    return implode("\n", [
        '2020-08-18 11:37:01 WARNING: disk space low',
        '2020-08-18 11:37:02 ERROR: connection refused',
        '2020-08-18 11:37:03 INFO: service started',
        '2020-08-18 11:37:04 DEBUG: request received',
        '2020-08-18 11:37:05 FATAL: out of memory',
    ]);
}

/** ISO date + process@pid: message (pattern B) */
function isoProcessContent(): string
{
    return implode("\n", [
        '2023-09-22 14:47:17 /usr/bin/kdumpctl@673: saving vmcore',
        '2023-09-22 14:47:18 /usr/bin/kdumpctl@673: vmcore saved',
        '2023-09-22 14:47:19 /usr/sbin/crond@1001: job started',
        '2023-09-22 14:47:20 /usr/sbin/crond@1001: job finished',
        '2023-09-22 14:47:21 /usr/bin/python3@2048: script complete',
    ]);
}

/** ISO date + bare message (pattern C) */
function isoBareContent(): string
{
    return implode("\n", [
        '2023-09-22 14:47:17 starting service',
        '2023-09-22 14:47:18 service running',
        '2023-09-22 14:47:19 health check passed',
        '2023-09-22 14:47:20 request processed',
        '2023-09-22 14:47:21 connection closed',
    ]);
}

/** Mostly non-log lines (prose) */
function nonLogContent(): string
{
    return implode("\n", [
        'This file is not a log.',
        'It contains plain text.',
        'No timestamps here.',
        'Just regular sentences.',
        'Nothing to parse.',
    ]);
}

// ---------------------------------------------------------------------------
// isLinuxLog — detection
// ---------------------------------------------------------------------------

it('returns is_logFile false for empty content', function () {
    $result = $this->vtools->isLinuxLog('', false);

    expect($result['is_logFile'])->toBeFalse();
});

it('detects classic syslog format', function () {
    $result = $this->vtools->isLinuxLog(syslogContent(), false);

    expect($result['is_logFile'])->toBeTrue();
});

it('detects ISO format with severity keyword', function () {
    $result = $this->vtools->isLinuxLog(isoSeverityContent(), false);

    expect($result['is_logFile'])->toBeTrue();
});

it('detects ISO format with process@pid', function () {
    $result = $this->vtools->isLinuxLog(isoProcessContent(), false);

    expect($result['is_logFile'])->toBeTrue();
});

it('detects ISO format with bare message', function () {
    $result = $this->vtools->isLinuxLog(isoBareContent(), false);

    expect($result['is_logFile'])->toBeTrue();
});

it('does not classify plain prose as a log file', function () {
    $result = $this->vtools->isLinuxLog(nonLogContent(), false);

    expect($result['is_logFile'])->toBeFalse();
});

it('does not classify a file where fewer than half the lines match', function () {
    $content = implode("\n", [
        'Mar  1 12:00:01 myhost sshd[1234]: login',
        'This is just plain text.',
        'Another plain line.',
        'And one more.',
    ]);

    $result = $this->vtools->isLinuxLog($content, false);

    expect($result['is_logFile'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// isLinuxLog — structure of a positive result
// ---------------------------------------------------------------------------

it('returns is_table true when log is detected', function () {
    $result = $this->vtools->isLinuxLog(syslogContent(), false);

    expect($result['is_table'])->toBeTrue();
});

it('returns separator log-format', function () {
    $result = $this->vtools->isLinuxLog(syslogContent(), false);

    expect($result['separator'])->toBe('log-format');
});

it('returns 9 fixed columns', function () {
    $result = $this->vtools->isLinuxLog(syslogContent(), false);

    expect($result['columns'])->toBe('9');
});

it('returns the nine canonical header names', function () {
    $result = $this->vtools->isLinuxLog(syslogContent(), false);

    expect($result['headers'])->toBe('line|date|time|host|process|pid|uid|severity|message');
});

it('returns has_header true', function () {
    $result = $this->vtools->isLinuxLog(syslogContent(), false);

    expect($result['has_header'])->toBeTrue();
});

it('returns header_row_index of -1 (synthetic header, no real header line)', function () {
    $result = $this->vtools->isLinuxLog(syslogContent(), false);

    expect($result['header_row_index'])->toBe(-1);
});

it('returns a content array with one entry per matched line', function () {
    $result = $this->vtools->isLinuxLog(syslogContent(), false);

    expect($result['content'])->toHaveCount(5);
});

it('collects unique sorted dates', function () {
    $content = implode("\n", [
        '2023-01-15 10:00:00 INFO: first',
        '2023-01-16 10:00:01 INFO: second',
        '2023-01-15 10:00:02 INFO: third duplicate date',
        '2023-01-17 10:00:03 INFO: fourth',
    ]);

    $result = $this->vtools->isLinuxLog($content, false);

    expect($result['dates'])->toBe(['2023-01-15', '2023-01-16', '2023-01-17']);
});

it('preserves the isTable value passed in when not a log file', function () {
    $result = $this->vtools->isLinuxLog(nonLogContent(), true);

    expect($result['is_table'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// parseLinuxLogLine — pattern A: ISO + SEVERITY:
// ---------------------------------------------------------------------------

it('parseLinuxLogLine parses ISO format with severity', function () {
    $entry = $this->vtools->parseLinuxLogLine('2020-08-18 11:37:01 WARNING: disk space low', 1);

    expect($entry)->not->toBeNull()
        ->and($entry['line'])->toBe(1)
        ->and($entry['date'])->toBe('2020-08-18')
        ->and($entry['time'])->toBe('11:37:01')
        ->and($entry['severity'])->toBe('WARNING')
        ->and($entry['message'])->toBe('disk space low')
        ->and($entry['host'])->toBeNull()
        ->and($entry['process'])->toBeNull();
});

// ---------------------------------------------------------------------------
// parseLinuxLogLine — pattern B: ISO + process@pid:
// ---------------------------------------------------------------------------

it('parseLinuxLogLine parses ISO format with process and pid', function () {
    $entry = $this->vtools->parseLinuxLogLine('2023-09-22 14:47:17 /usr/bin/kdumpctl@673: saving vmcore', 2);

    expect($entry)->not->toBeNull()
        ->and($entry['date'])->toBe('2023-09-22')
        ->and($entry['time'])->toBe('14:47:17')
        ->and($entry['process'])->toBe('/usr/bin/kdumpctl')
        ->and($entry['pid'])->toBe('673')
        ->and($entry['message'])->toBe('saving vmcore');
});

it('parseLinuxLogLine parses ISO format with process and no pid', function () {
    $entry = $this->vtools->parseLinuxLogLine('2023-09-22 14:47:17 /usr/bin/kdumpctl: saving vmcore', 3);

    expect($entry)->not->toBeNull()
        ->and($entry['process'])->toBe('/usr/bin/kdumpctl')
        ->and($entry['pid'])->toBeEmpty(); // optional capture group yields '' when absent
});

// ---------------------------------------------------------------------------
// parseLinuxLogLine — pattern C: ISO + bare message
// ---------------------------------------------------------------------------

it('parseLinuxLogLine parses ISO format with bare message', function () {
    $entry = $this->vtools->parseLinuxLogLine('2023-09-22 14:47:17 starting service', 4);

    expect($entry)->not->toBeNull()
        ->and($entry['date'])->toBe('2023-09-22')
        ->and($entry['time'])->toBe('14:47:17')
        ->and($entry['message'])->toBe('starting service')
        ->and($entry['severity'])->toBeNull()
        ->and($entry['process'])->toBeNull();
});

// ---------------------------------------------------------------------------
// parseLinuxLogLine — pattern D: classic syslog
// ---------------------------------------------------------------------------

it('parseLinuxLogLine parses classic syslog with severity in message', function () {
    $entry = $this->vtools->parseLinuxLogLine('Mar  1 12:00:01 myhost kernel: WARNING: low memory', 5);

    expect($entry)->not->toBeNull()
        ->and($entry['date'])->toBe('Mar 1')
        ->and($entry['time'])->toBe('12:00:01')
        ->and($entry['host'])->toBe('myhost')
        ->and($entry['process'])->toBe('kernel')
        ->and($entry['message'])->toBe('low memory');
});

it('parseLinuxLogLine parses classic syslog with explicit pid', function () {
    $entry = $this->vtools->parseLinuxLogLine('Mar  1 12:00:02 myhost sshd[1234]: accepted publickey', 6);

    expect($entry)->not->toBeNull()
        ->and($entry['host'])->toBe('myhost')
        ->and($entry['process'])->toBe('sshd')
        ->and($entry['pid'])->toBe('1234')
        ->and($entry['message'])->toBe('accepted publickey');
});

it('parseLinuxLogLine returns null for a non-matching line', function () {
    expect($this->vtools->parseLinuxLogLine('this is not a log line', 1))->toBeNull();
});

it('parseLinuxLogLine returns null for an empty string', function () {
    expect($this->vtools->parseLinuxLogLine('', 1))->toBeNull();
});

// ---------------------------------------------------------------------------
// cleanValue
// ---------------------------------------------------------------------------

it('cleanValue trims surrounding whitespace', function () {
    expect($this->vtools->cleanValue('  hello  '))->toBe('hello');
});

it('cleanValue strips a trailing percent sign', function () {
    expect($this->vtools->cleanValue('95%'))->toBe('95');
});

it('cleanValue does not strip a leading percent sign', function () {
    expect($this->vtools->cleanValue('%usr'))->toBe('%usr');
});

it('cleanValue leaves a plain string unchanged', function () {
    expect($this->vtools->cleanValue('hello'))->toBe('hello');
});
