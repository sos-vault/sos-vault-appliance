<?php

use App\Services\Ai\SourceSelector;

beforeEach(function () {
    $this->selector = new SourceSelector;
});

it('selects the right source from the catalog text alone', function (string $question, string $expected) {
    expect($this->selector->select($question, 4))->toContain($expected);
})->with([
    'memory pressure' => ['why is the system out of memory?', 'memory'],
    'swap' => ['is the machine swapping?', 'memory'],
    'listening ports' => ['what processes are listening on tcp ports?', 'network'],
    'port ownership' => ['which process owns port 5432?', 'network'],
    'package version' => ['what openssl version is installed?', 'packages'],
    'open files by pid' => ['how many open files does the process 4148341 have?', 'open_files'],
    'cpu saturation' => ['is the cpu saturated or io bound?', 'cpu'],
    'disk full' => ['is any filesystem full?', 'disks'],
    'failed unit' => ['what unit was marked failed by systemd?', 'systemd'],
    'load average' => ['what is the system load average?', 'host'],
    'firewall' => ['is traffic to that port blocked by the firewall?', 'firewall'],
    'sysctl' => ['how is memory overcommit configured?', 'kernel_params'],
]);

it('matches plural nouns (process->processes, port->ports)', function () {
    expect($this->selector->select('how many processes are running?'))->toContain('processes');
    expect($this->selector->select('which ports are open?'))->toContain('network');
});

it('ranks the most on-topic source first', function () {
    // A memory question should rank the memory source above anything incidental.
    expect($this->selector->select('why is the system low on memory and swapping?')[0])->toBe('memory');
});

it('returns nothing for a question with no data signal', function () {
    expect($this->selector->select('hello, can you help me?'))->toBe([]);
});

it('honours the limit', function () {
    expect(count($this->selector->select('memory cpu disk network process', 2)))->toBe(2);
});

it('maps selected sources to their output files', function () {
    expect($this->selector->selectFiles('what openssl version is installed?'))
        ->toContain('.packagesData.json');
});
