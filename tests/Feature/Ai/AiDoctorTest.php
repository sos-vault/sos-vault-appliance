<?php

/**
 * ai:doctor — non-interactive Mil health check.
 *
 * The command is the production-safe alternative to `php artisan tinker` for
 * diagnosing the assistant; its headline job is catching a KB that isn't shipping
 * (the .dockerignore/agent regression), so these tests pin exactly that.
 */

use Illuminate\Support\Facades\Log;

it('reports a healthy assistant and exits 0 when all KB files are present', function () {
    $this->artisan('ai:doctor')
        ->expectsOutputToContain('Mil AI assistant')
        ->expectsOutputToContain('sos_vault.md')
        ->expectsOutputToContain('All configured knowledge-base files are present')
        ->assertExitCode(0);
});

it('flags a missing KB file and exits non-zero', function () {
    // Simulate the shipped-without-agent/ failure: a configured file not on disk.
    config(['ai.knowledge.sos_vault' => 'kb/does_not_exist.md']);
    Log::spy();

    $this->artisan('ai:doctor')
        ->expectsOutputToContain('MISSING')
        ->assertExitCode(1);
});

it('shows the resolved provider profile budget', function () {
    config(['ai.provider' => 'openai']);

    $this->artisan('ai:doctor')
        ->expectsOutputToContain('openai')
        ->expectsOutputToContain('max_knowledge_chars')
        ->assertExitCode(0);
});
