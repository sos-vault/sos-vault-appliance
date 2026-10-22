<?php

use App\Services\Ai\CaseContextBuilder;
use App\Services\Ai\ProviderProfile;

beforeEach(function () {
    $this->builder = new CaseContextBuilder;
    $this->cloud = ProviderProfile::for('openai');
    $this->local = ProviderProfile::for('local');

    $this->dir = sys_get_temp_dir().'/ccb_'.uniqid();
    mkdir($this->dir);

    file_put_contents($this->dir.'/.aiDigest.json', json_encode([
        'host' => ['hostname' => 'web01', 'cores' => 4],
        'memory' => ['used_pct' => 94.2, 'status' => 'critical'],
        'flags' => ['memory_pressure_94.2pct'],
    ]));
    file_put_contents($this->dir.'/.memoryData.json', json_encode([
        'memory' => ['total' => ['value' => 16777216000], 'pused' => ['value' => 94.2]],
    ]));
    file_put_contents($this->dir.'/.disksData.json', json_encode([
        ['point' => '/', 'pused' => '40', 'fstype' => 'xfs'],
    ]));

    // 40 processes; cpu and rss both increase with pid, so the heaviest are pid 1026..1040.
    $procs = [];
    for ($i = 1; $i <= 40; $i++) {
        $procs[(string) (1000 + $i)] = [
            'PID' => 1000 + $i,
            'USER' => 'root',
            'Command' => "proc{$i}",
            '%CPU' => (string) $i,
            '%MEM' => '0.1',
            'RSS' => $i * 1_000_000,
            'fd-nr' => (string) ($i * 3),
        ];
    }
    $procs['tasks'] = ['tasks' => 40, 'running' => 2, 'zombie' => 1];
    file_put_contents($this->dir.'/.processesData.json', json_encode($procs));

    // A mix of LISTEN and ESTABLISHED connections; a LISTEN socket must survive
    // even when it is not near the head of the (potentially huge) list.
    $conns = [];
    for ($i = 0; $i < 90; $i++) {
        $conns[] = [
            'Proto' => 'tcp', 'Local_Address' => "10.0.0.1:5000{$i}",
            'Foreign_Address' => '10.0.0.2:443', 'State' => 'ESTABLISHED',
            'PID' => (string) (2000 + $i), 'Program_name' => 'curl',
        ];
    }
    $conns[] = [
        'Proto' => 'tcp', 'Local_Address' => '0.0.0.0:5432',
        'Foreign_Address' => '0.0.0.0:*', 'State' => 'LISTEN',
        'PID' => '999', 'Program_name' => 'postgres',
    ];
    file_put_contents($this->dir.'/.networkData.json', json_encode($conns));

    file_put_contents($this->dir.'/.packagesData.json', json_encode([
        ['Name' => 'openssl-1.1.1k-9.el8.x86_64', 'Date' => '2023-01-02'],
        ['Name' => 'glibc-2.28-211.el8.x86_64', 'Date' => '2023-01-02'],
        ['Name' => 'bash-4.4.20-4.el8.x86_64', 'Date' => '2023-01-02'],
    ]));
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    array_map('unlink', glob($this->dir.'/.*[!.]*') ?: []);
    @rmdir($this->dir);
});

it('returns an empty string when case analysis is disabled (local model)', function () {
    expect($this->builder->buildFromPath($this->dir, 'why is memory high?', $this->local))->toBe('');
});

it('always injects the health digest', function () {
    $out = $this->builder->buildFromPath($this->dir, 'why is memory high?', $this->cloud);

    expect($out)->toContain('Live Case System Data')
        ->toContain('### digest')
        ->toContain('memory_pressure');
});

it('injects only the topic file the question references', function () {
    $out = $this->builder->buildFromPath($this->dir, 'why is memory high?', $this->cloud);

    expect($out)->toContain('### memoryData')
        ->not->toContain('### disksData');
});

it('trims the process map to the heaviest rows, not the first PIDs', function () {
    $out = $this->builder->buildFromPath($this->dir, 'which process uses the most cpu?', $this->cloud);

    expect($out)->toContain('### processesData')
        ->toContain('proc40')        // heaviest, kept
        ->toContain('tasks')          // task summary preserved
        ->not->toContain('proc5');    // light process, dropped by top-N trim
});

it('includes an explicitly named PID even when it is not a heavy hitter', function () {
    // proc2 (pid 1002) is light — normally dropped — but the question names it.
    $out = $this->builder->buildFromPath($this->dir, 'how many open files does PID 1002 have?', $this->cloud);

    expect($out)->toContain('### processesData')
        ->toContain('"proc2"')   // the named process is kept
        ->toContain('fd-nr')      // open-file-descriptor count is exposed
        ->toContain('"1002"');
});

it('matches plural nouns so "processes" routes to the process file', function () {
    $out = $this->builder->buildFromPath($this->dir, 'how many processes are running?', $this->cloud);

    expect($out)->toContain('### processesData');
});

it('routes listening-port questions to the network file and keeps LISTEN sockets', function () {
    $out = $this->builder->buildFromPath($this->dir, 'what processes are listening on tcp ports?', $this->cloud);

    expect($out)->toContain('### networkData')
        ->toContain('postgres')   // the sole LISTEN socket survives the trim
        ->toContain('5432');
});

it('returns only the named package for a package-version question', function () {
    $out = $this->builder->buildFromPath($this->dir, 'what openssl version is installed?', $this->cloud);

    expect($out)->toContain('### packagesData')
        ->toContain('openssl-1.1.1k')
        ->not->toContain('glibc');   // unrelated packages are not dumped
});
