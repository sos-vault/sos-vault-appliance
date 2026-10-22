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
// Helpers — realistic file content strings
// ---------------------------------------------------------------------------

/**
 * Simulates pidstat -tl output:
 * - line 0: kernel banner  (different column count → excluded from rows)
 * - line 1: blank
 * - line 2: actual header  (original file line 2)
 * - line 3+: data rows
 */
function pidstatContent(): string
{
    return implode("\n", [
        'Linux 4.15.0-1128-fips (host1)     04/14/25     _x86_64_    (4 CPU)',
        '',
        '16:54:03      UID      TGID       TID    %usr %system  %guest   %wait    %CPU   CPU  Command',
        '16:54:03        0         1         -    0.03    0.00    0.00    0.00    0.03     0  /sbin/init splash',
        '16:54:03        0         -         1    0.03    0.00    0.00    0.00    0.03     0  |__/sbin/init',
        '16:54:03        0         2         -    0.00    0.00    0.00    0.00    0.00     3  kthreadd',
        '16:54:03        0         -         2    0.00    0.00    0.00    0.00    0.00     3  |__kthreadd',
        '16:54:03        0         7         -    0.00    0.01    0.00    0.01    0.01     0  ksoftirqd/0',
    ]);
}

/**
 * Simulates `netstat -a` style output: header on the first non-blank line.
 */
function netstatContent(): string
{
    return implode("\n", [
        'Proto Recv-Q Send-Q Local-Address           Foreign-Address         State',
        'tcp        0      0 0.0.0.0:ssh             0.0.0.0:*               LISTEN',
        'tcp        0      0 0.0.0.0:smtp            0.0.0.0:*               LISTEN',
        'tcp6       0      0 [::]:ssh                [::]:*                  LISTEN',
        'tcp6       0      0 [::]:smtp               [::]:*                  LISTEN',
        'udp        0      0 0.0.0.0:bootpc          0.0.0.0:*               ',
    ]);
}

/**
 * CSV content with a header row.
 */
function csvContent(): string
{
    return implode("\n", [
        'name,age,city',
        'Alice,30,London',
        'Bob,25,Paris',
        'Carol,35,Berlin',
        'Dave,28,Madrid',
    ]);
}

/**
 * Tab-separated content with a header row.
 */
function tsvContent(): string
{
    return implode("\n", [
        "host\tcpu\tmem\tdisk",
        "server1\t45.2\t78.1\t60.0",
        "server2\t12.0\t55.3\t88.4",
        "server3\t90.1\t91.2\t42.7",
        "server4\t33.4\t60.0\t55.1",
    ]);
}

/**
 * Plain prose with highly irregular word counts per line — column consistency
 * check should reject this as a table.
 */
function proseContent(): string
{
    return implode("\n", [
        'Hello.',
        'This is a slightly longer sentence with many more words in it.',
        'Short.',
        'Another very long line that goes on and on with many words and phrases.',
        'Hi there.',
        'This sentence has a moderate length and several words in it here.',
    ]);
}

/**
 * Mirrors the real `systemctl list-units` file structure:
 * - 6-field header (UNIT LOAD ACTIVE SUB JOB DESCRIPTION) — all uppercase
 * - data lines with no leading ● and variable-length DESCRIPTION (5–12 fields)
 * - one line starting with ● (failed unit)
 * - footer legend block (key=value format) and summary lines
 *
 * Two problems to solve:
 * 1. No single column count dominates → P25 approach selects columnCount=6.
 * 2. All columns are non-numeric text → header found via uppercase case-mismatch.
 */
function systemctlListUnitsContent(): string
{
    return implode("\n", [
        ' UNIT                         LOAD   ACTIVE  SUB      JOB DESCRIPTION',
        '  proc-sys-fs-binfmt_misc.automount loaded active running     Arbitrary File Formats',
        '  sys-devices-sda.device       loaded active plugged         QEMU_HARDDISK',
        '  auditd.service               loaded active running         Security Auditing Service',
        '  chronyd.service              loaded active running         NTP client/server',
        '  dbus-broker.service          loaded active running         D-Bus System Message Bus',
        '  getty@tty1.service           loaded active running         Getty on tty1',
        '  gssproxy.service             loaded active running         GSSAPI Proxy Daemon',
        '● iptables.service             loaded failed  failed          IPv4 firewall with iptables',
        '  irqbalance.service           loaded active running         irqbalance daemon',
        '  mariadb.service              loaded active running         MariaDB Database',
        '',
        'LOAD   = Reflects whether the unit definition was properly loaded.',
        'ACTIVE = The high-level unit activation state, i.e. generalization of SUB.',
        'SUB    = The low-level unit activation state, values depend on unit type.',
        'JOB    = Pending job for the unit.',
        '',
        '10 loaded units listed. Pass --all to see loaded but inactive units, too.',
        "To show all installed unit files use 'systemctl list-unit-files'.",
    ]);
}

// ---------------------------------------------------------------------------
// isTable — basic detection
// ---------------------------------------------------------------------------

it('detects a space-separated table', function () {
    $result = $this->vtools->isTable(netstatContent());

    expect($result['is_table'])->toBeTrue()
        ->and($result['separator'])->toBe('space');
});

it('detects a CSV table', function () {
    $result = $this->vtools->isTable(csvContent());

    expect($result['is_table'])->toBeTrue()
        ->and($result['separator'])->toBe(',');
});

it('detects a tab-separated table', function () {
    $result = $this->vtools->isTable(tsvContent());

    expect($result['is_table'])->toBeTrue()
        ->and($result['separator'])->toBe("\t");
});

it('returns correct column count for netstat', function () {
    $result = $this->vtools->isTable(netstatContent());

    expect($result['columns'])->toBe(6);
});

it('returns correct column count for CSV', function () {
    $result = $this->vtools->isTable(csvContent());

    expect($result['columns'])->toBe(3);
});

it('does not classify prose as a table', function () {
    $result = $this->vtools->isTable(proseContent());

    expect($result['is_table'])->toBeFalse();
});

it('detects systemctl list-units output as a space-separated table', function () {
    $result = $this->vtools->isTable(systemctlListUnitsContent());

    expect($result['is_table'])->toBeTrue()
        ->and($result['separator'])->toBe('space');
});

it('returns correct column count for systemctl list-units', function () {
    $result = $this->vtools->isTable(systemctlListUnitsContent());

    // P25 of the field-count distribution resolves to 6 (header + most data
    // lines have ≥6 space-separated tokens when description has ≥2 words).
    expect($result['columns'])->toBe(6);
});

it('detects a header row in systemctl list-units via uppercase case-mismatch', function () {
    $result = $this->vtools->isTable(systemctlListUnitsContent());

    // The header "UNIT LOAD ACTIVE SUB JOB DESCRIPTION" has 6 fields = columnCount,
    // so it lands inside $rows. detectHeaderRowIndex finds it because its fields
    // are all-uppercase (UNIT, LOAD, ACTIVE…) while data values are lowercase
    // (loaded, active, running…) — a case-based mismatch on every column.
    expect($result['has_header'])->toBeTrue()
        ->and($result['header_row_index'])->toBe(0);
});

it('returns false for a single-line input', function () {
    $result = $this->vtools->isTable('just one line');

    expect($result['is_table'])->toBeFalse();
});

it('returns false for empty input', function () {
    $result = $this->vtools->isTable('');

    expect($result['is_table'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// isTable — header detection
// ---------------------------------------------------------------------------

it('detects a header row in netstat output', function () {
    $result = $this->vtools->isTable(netstatContent());

    expect($result['has_header'])->toBeTrue();
});

it('detects a header row in CSV output', function () {
    $result = $this->vtools->isTable(csvContent());

    expect($result['has_header'])->toBeTrue();
});

it('detects a header row in TSV output', function () {
    $result = $this->vtools->isTable(tsvContent());

    expect($result['has_header'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// isTable — header_row_index maps to the ORIGINAL file line
// ---------------------------------------------------------------------------

it('returns header_row_index 0 for netstat (header is first line)', function () {
    $result = $this->vtools->isTable(netstatContent());

    expect($result['header_row_index'])->toBe(0);
});

it('returns header_row_index 0 for CSV (header is first line)', function () {
    $result = $this->vtools->isTable(csvContent());

    expect($result['header_row_index'])->toBe(0);
});

it('returns header_row_index 0 for TSV (header is first line)', function () {
    $result = $this->vtools->isTable(tsvContent());

    expect($result['header_row_index'])->toBe(0);
});

/**
 * Core regression: pidstat has a banner + blank line before the real header.
 * header_row_index must be 2 (original file line 2), NOT 0 (the banner).
 */
it('returns header_row_index 2 for pidstat output — banner and blank precede the real header', function () {
    $result = $this->vtools->isTable(pidstatContent());

    expect($result['is_table'])->toBeTrue()
        ->and($result['has_header'])->toBeTrue()
        ->and($result['header_row_index'])->toBe(2);
});

it('identifies correct column count for pidstat output', function () {
    $result = $this->vtools->isTable(pidstatContent());

    expect($result['columns'])->toBe(11);
});

// ---------------------------------------------------------------------------
// isTable — files without a detectable header
// ---------------------------------------------------------------------------

it('returns null header_row_index when all columns are numeric (no header)', function () {
    $content = implode("\n", [
        '1.0 2.0 3.0 4.0',
        '5.1 6.2 7.3 8.4',
        '9.0 1.1 2.2 3.3',
        '4.4 5.5 6.6 7.7',
        '8.8 9.9 0.0 1.1',
    ]);

    $result = $this->vtools->isTable($content);

    expect($result['is_table'])->toBeTrue()
        ->and($result['has_header'])->toBeFalse()
        ->and($result['header_row_index'])->toBeNull();
});

// ---------------------------------------------------------------------------
// detectHeaderRowIndex — unit-level tests
// ---------------------------------------------------------------------------

it('returns null when fewer than 2 valid rows are given', function () {
    $rows = [['host', 'cpu', 'mem']];

    expect($this->vtools->detectHeaderRowIndex($rows, 3))->toBeNull();
});

it('returns null for an empty rows array', function () {
    expect($this->vtools->detectHeaderRowIndex([], 3))->toBeNull();
});

it('identifies the text header row in a simple rows array', function () {
    $rows = [
        ['host', 'cpu', 'mem', 'disk'],
        ['server1', '45.2', '78.1', '60.0'],
        ['server2', '12.0', '55.3', '88.4'],
        ['server3', '90.1', '91.2', '42.7'],
        ['server4', '33.4', '60.0', '55.1'],
    ];

    expect($this->vtools->detectHeaderRowIndex($rows, 4))->toBe(0);
});

it('detects header even when it is not the first row', function () {
    $rows = [
        ['server1', '45.2', '78.1', '60.0'],
        ['host', 'cpu', 'mem', 'disk'],
        ['server2', '12.0', '55.3', '88.4'],
        ['server3', '90.1', '91.2', '42.7'],
        ['server4', '33.4', '60.0', '55.1'],
    ];

    expect($this->vtools->detectHeaderRowIndex($rows, 4))->toBe(1);
});

it('returns null when all rows are uniformly numeric', function () {
    $rows = [
        ['1.0', '2.0', '3.0'],
        ['4.0', '5.0', '6.0'],
        ['7.0', '8.0', '9.0'],
        ['1.1', '2.2', '3.3'],
        ['4.4', '5.5', '6.6'],
    ];

    expect($this->vtools->detectHeaderRowIndex($rows, 3))->toBeNull();
});

it('detects an all-uppercase header via case-based signal when all columns are text', function () {
    // Mirrors systemctl list-units: every column is non-numeric, so the
    // existing numeric-mismatch signal scores 0. The new case signal detects
    // UNIT/LOAD/ACTIVE/SUB as all-uppercase labels vs lowercase data values.
    $rows = [
        ['UNIT', 'LOAD', 'ACTIVE', 'SUB', 'DESCRIPTION'],
        ['auditd.service', 'loaded', 'active', 'running', 'Security Auditing Service'],
        ['chronyd.service', 'loaded', 'active', 'running', 'NTP client/server'],
        ['dbus.service', 'loaded', 'active', 'running', 'D-Bus System Message Bus'],
        ['nginx.service', 'loaded', 'active', 'running', 'The nginx HTTP server'],
    ];

    expect($this->vtools->detectHeaderRowIndex($rows, 5))->toBe(0);
});

it('handles percentage-sign column labels as non-numeric header fields', function () {
    $rows = [
        ['time', 'uid', 'pid', '%usr', '%system', '%cpu', 'command'],
        ['16:54:03', '0', '1', '0.03', '0.00', '0.03', '/sbin/init'],
        ['16:54:03', '0', '2', '0.00', '0.00', '0.00', 'kthreadd'],
        ['16:54:03', '0', '7', '0.00', '0.01', '0.01', 'ksoftirqd'],
        ['16:54:03', '0', '8', '0.00', '0.03', '0.03', 'rcu_sched'],
    ];

    expect($this->vtools->detectHeaderRowIndex($rows, 7))->toBe(0);
});
