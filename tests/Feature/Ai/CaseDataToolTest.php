<?php

use App\Services\Ai\CaseDataTool;
use App\Services\Ai\ProviderProfile;

beforeEach(function () {
    $this->tool = new CaseDataTool;
    $this->cloud = ProviderProfile::for('openai');

    $this->dir = sys_get_temp_dir().'/cdt_'.uniqid();
    mkdir($this->dir);

    $conns = [];
    for ($i = 0; $i < 90; $i++) {
        $conns[] = ['Proto' => 'tcp', 'Local_Address' => "10.0.0.1:5000{$i}", 'State' => 'ESTABLISHED', 'PID' => (string) (2000 + $i), 'Program_name' => 'curl'];
    }
    $conns[] = ['Proto' => 'tcp', 'Local_Address' => '0.0.0.0:5432', 'State' => 'LISTEN', 'PID' => '999', 'Program_name' => 'postgres'];
    file_put_contents($this->dir.'/.networkData.json', json_encode($conns));

    file_put_contents($this->dir.'/.packagesData.json', json_encode([
        ['Name' => 'openssl-1.1.1k-9.el8.x86_64', 'Date' => '2023-01-02'],
        ['Name' => 'glibc-2.28-211.el8.x86_64', 'Date' => '2023-01-02'],
    ]));

    $procs = [];
    for ($i = 1; $i <= 40; $i++) {
        $procs[(string) (1000 + $i)] = [
            'PID' => 1000 + $i, 'USER' => 'root', 'Command' => "proc{$i}",
            '%CPU' => (string) $i, 'RSS' => $i * 1_000_000, 'fd-nr' => (string) ($i * 3),
        ];
    }
    file_put_contents($this->dir.'/.processesData.json', json_encode($procs));
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    array_map('unlink', glob($this->dir.'/.*[!.]*') ?: []);
    @rmdir($this->dir);
});

it('fetches a source as raw JSON (no markdown wrapper)', function () {
    $out = $this->tool->fetchFromPath('network', 'listen', $this->dir, $this->cloud);

    expect($out)->toContain('postgres')->toContain('5432')
        ->not->toContain('### network'); // raw JSON for the tool, not a prompt block
});

it('applies the filter to a targeted source (package by name)', function () {
    $out = $this->tool->fetchFromPath('packages', 'openssl', $this->dir, $this->cloud);

    expect($out)->toContain('openssl-1.1.1k')->not->toContain('glibc');
});

it('includes an explicitly filtered PID even when it is light', function () {
    $out = $this->tool->fetchFromPath('processes', 'PID 1002', $this->dir, $this->cloud);

    expect($out)->toContain('"proc2"')->toContain('fd-nr');
});

it('rejects an unknown source and lists the valid ones', function () {
    $out = $this->tool->fetchFromPath('nonsense', '', $this->dir, $this->cloud);

    expect($out)->toContain('Unknown source')->toContain('processes')->toContain('network');
});

it('reports when a known source has no captured file', function () {
    $out = $this->tool->fetchFromPath('cpu', '', $this->dir, $this->cloud);

    expect($out)->toContain("No 'cpu' data");
});

it('builds a Prism tool named fetch_case_data with source and filter params', function () {
    $prismTool = $this->tool->prismTool(1, 1, $this->cloud);

    expect($prismTool->name())->toBe('fetch_case_data');
    expect(array_keys($prismTool->parameters()))->toContain('source', 'filter');
});

it('guides the model with the sources, the join graph and a method', function () {
    $guide = $this->tool->catalogGuide();

    expect($guide)->toContain('fetch_case_data')
        ->toContain('`processes`')
        ->toContain('`network`')
        ->toContain('↔')          // the correlation graph is present
        ->toContain('root-cause'); // the reasoning method is present
});
