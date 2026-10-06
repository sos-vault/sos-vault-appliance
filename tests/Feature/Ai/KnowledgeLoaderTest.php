<?php

use App\Enums\AiIntent;
use App\Services\Ai\KnowledgeLoader;
use App\Services\Ai\ProviderProfile;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->loader = new KnowledgeLoader;
    $this->cloud = ProviderProfile::for('openai');   // case analysis enabled
    $this->local = ProviderProfile::for('local');    // case analysis disabled
});

it('always includes the Mil instructions', function () {
    $out = $this->loader->loadFor(AiIntent::Linux, $this->cloud, 'anything');

    expect($out)->toContain('You are **Mil**');
});

it('loads the sos-vault knowledge for SosVault and not the sos command doc', function () {
    $out = $this->loader->loadFor(AiIntent::SosVault, $this->cloud, 'how do I upload?');

    expect($out)->toContain('sos-vault — Usage & Navigation Reference')
        ->not->toContain('Command & Report Structure');
});

it('loads the sos command knowledge for SosCommand', function () {
    $out = $this->loader->loadFor(AiIntent::SosCommand, $this->cloud, 'how to run sos report');

    expect($out)->toContain('Command & Report Structure');
});

it('loads no extra knowledge doc for Linux (relies on the model)', function () {
    $out = $this->loader->loadFor(AiIntent::Linux, $this->cloud, 'how do hard links work');

    expect($out)->toContain('You are **Mil**')
        ->not->toContain('Usage & Navigation Reference')
        ->not->toContain('Command & Report Structure')
        ->not->toContain('Analysis Guide');
});

it('injects only a named plugin entry when the message mentions a plugin', function () {
    $out = $this->loader->loadFor(AiIntent::SosCommand, $this->cloud, 'what does the networking plugin collect?');

    expect($out)->toContain('Referenced sos plugins')
        ->toContain('networking');
});

it('does not inject plugin entries without the word plugin', function () {
    $out = $this->loader->loadFor(AiIntent::SosCommand, $this->cloud, 'how do I run sos report');

    expect($out)->not->toContain('Referenced sos plugins');
});

it('loads the analysis guide for CaseAnalysis on a capable provider', function () {
    $out = $this->loader->loadFor(AiIntent::CaseAnalysis, $this->cloud, 'why is memory high');

    expect($out)->toContain('Analysis Guide');
});

it('emits a manual-steer instead of the analysis guide on the local model', function () {
    $out = $this->loader->loadFor(AiIntent::CaseAnalysis, $this->local, 'why is memory high');

    expect($out)->toContain('Current-sosreport analysis is unavailable here')
        ->not->toContain('Analysis Guide');
});

it('loads every file when an area is configured as an ordered list', function () {
    // The appliance points SosVault at [operator FAQ, shared app guide] so the
    // local model front-loads operator content within its budget.
    config(['ai.knowledge.sos_vault' => ['kb/sos_vault_appliance.md', 'kb/sos_vault.md']]);

    $out = $this->loader->loadFor(AiIntent::SosVault, $this->cloud, 'how do I install a license?');

    expect($out)->toContain('Self-Hosted Appliance — Operator & Admin Reference')
        ->toContain('sos-vault — Usage & Navigation Reference');

    // Order is honoured: the operator FAQ comes before the shared app guide.
    expect(strpos($out, 'Operator & Admin Reference'))
        ->toBeLessThan(strpos($out, 'Usage & Navigation Reference'));
});

it('documents the cross-case File Compare workflow for SosVault questions', function () {
    // Regression for the #9 bad answer (Mil suggested `diff` instead of the app's
    // Compare icon). The precise workflow must be in the KB.
    $out = $this->loader->loadFor(AiIntent::SosVault, $this->cloud, 'how do I compare two files from different cases?');

    expect($out)->toContain('File Compare')
        ->toContain('Compare');
});

it('fits both SosVault docs in the cloud budget without truncation', function () {
    // The appliance ships app guide + operator FAQ; the default/ollama budgets must
    // be large enough to hold both in full for a capable provider.
    config(['ai.knowledge.sos_vault' => ['kb/sos_vault.md', 'kb/sos_vault_appliance.md']]);

    $out = $this->loader->loadFor(AiIntent::SosVault, $this->cloud, 'how do I share a file?');

    expect($out)->toContain('sos-vault — Usage & Navigation Reference')
        ->toContain('Self-Hosted Appliance — Operator & Admin Reference')
        ->not->toContain('[truncated]');
});

it('references in-app documentation by local path, never an external URL', function () {
    // The sos-command KB points at the on-box /blog docs; production URLs would
    // send self-hosted users off their own appliance.
    $out = $this->loader->loadFor(AiIntent::SosCommand, $this->cloud, 'how do I use sos report presets?');

    expect($out)->toContain('/blog/sos-command/18-using-sos-report-presets')
        ->not->toContain('https://sos-vault.com/blog');
});

it('logs a warning when a configured KB file is missing from the deployment', function () {
    // Regression aid for the "generic answer" symptom: if the deployed build (or a
    // stale config:cache) points SosVault at a file that isn't on disk, the area
    // silently falls back to bare instructions. Surface it in the logs instead.
    config(['ai.knowledge.sos_vault' => 'kb/does_not_exist.md']);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'kb/does_not_exist.md'));

    $out = $this->loader->loadFor(AiIntent::SosVault, $this->cloud, 'how do I upload?');

    // Falls back to the always-loaded instructions, no area doc.
    expect($out)->toContain('You are **Mil**')
        ->not->toContain('Usage & Navigation Reference');
});

it('respects the knowledge character budget', function () {
    // Budget leaves a little room past the always-loaded instructions, so the
    // area doc must be truncated to fit.
    $instructionsLen = strlen(file_get_contents(config('ai.system_prompt_path').'/instructions.md'));
    $budget = new ProviderProfile('openai', true, $instructionsLen + 300, 1200, 6);

    $out = $this->loader->loadFor(AiIntent::SosVault, $budget, 'how do I upload?');

    expect($out)->toContain('[truncated]')
        ->and(strlen($out))->toBeLessThan($instructionsLen + 500);
});
