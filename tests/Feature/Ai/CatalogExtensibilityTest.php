<?php

use App\Services\Ai\CaseDataTool;
use App\Services\Ai\ProviderProfile;
use App\Services\Ai\SourceSelector;

// The whole point of the Data Catalog is that adding a source (or a new capture
// domain like OpenStack / Kubernetes) is a DATA change, not a code change. This
// proves it: a source registered only at runtime — touching no PHP — is picked up
// by BOTH retrieval paths (the single-shot SourceSelector and the agentic tool).

it('discovers a newly registered source on both retrieval paths, with no code change', function () {
    // Register a synthetic source purely via config (as a new domain/catalog entry would).
    config(['ai_case_catalog.sources.widgets' => [
        'file' => '.widgetsData.json',
        'title' => 'Widget telemetry',
        'purpose' => 'Per-widget spin rate and torque for the widget subsystem.',
        'shape' => 'array',
        'keyed_by' => null,
        'fields' => ['spin' => 'Spin rate (rpm).', 'torque' => 'Torque (nm).'],
        'joins' => [],
        'answers' => ['what is the widget spin rate', 'is any widget over-torqued'],
    ]]);

    // 1) Single-shot path: selectable from the catalog text alone (no keyword map).
    $selected = (new SourceSelector)->select('what is the widget spin rate?');
    expect($selected)->toContain('widgets');

    // 2) Agentic path: the tool serves it, and the guide advertises it.
    $tool = new CaseDataTool;
    expect($tool->catalogGuide())->toContain('`widgets`');

    $dir = sys_get_temp_dir().'/ext_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/.widgetsData.json', json_encode([['spin' => 3000, 'torque' => 12]]));

    try {
        $out = $tool->fetchFromPath('widgets', '', $dir, ProviderProfile::for('openai'));
        expect($out)->toContain('3000')->toContain('torque');
    } finally {
        @unlink($dir.'/.widgetsData.json');
        @rmdir($dir);
    }
});

it('does not select or serve a source that was never registered', function () {
    expect((new SourceSelector)->select('what is the widget spin rate?'))->not->toContain('widgets');

    $out = (new CaseDataTool)->fetchFromPath('widgets', '', sys_get_temp_dir(), ProviderProfile::for('openai'));
    expect($out)->toContain('Unknown source');
});
