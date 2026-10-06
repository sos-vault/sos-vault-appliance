<?php

use App\Enums\AiIntent;
use App\Services\Ai\IntentRouter;

beforeEach(function () {
    $this->router = new IntentRouter;
});

it('routes sos-vault application questions to SosVault', function (string $message) {
    expect($this->router->classify($message, false))->toBe(AiIntent::SosVault);
})->with([
    'How do I upload a report in sos-vault?',
    'Where is the Compare tool in the vault?',
    'How do I share a file and add a note?',
    'Where do I find the API key in settings?',
    'How do I configure the Jira ITSM provider?',
]);

it('routes sos command questions to SosCommand', function (string $message) {
    expect($this->router->classify($message, false))->toBe(AiIntent::SosCommand);
})->with([
    'How do I run sos report with obfuscation?',
    'What does the --clean option do in sosreport?',
    'How do I generate a report with sos collect?',
    'How do I exclude a plugin when running sos?',
]);

it('routes generic Linux questions to Linux', function (string $message) {
    expect($this->router->classify($message, false))->toBe(AiIntent::Linux);
})->with([
    'How does the OOM killer decide which process to kill?',
    'What is the difference between a hard and soft link?',
    'Explain how cron expressions work.',
]);

it('routes analytical questions about the open case to CaseAnalysis when a case is open', function (string $message) {
    expect($this->router->classify($message, true))->toBe(AiIntent::CaseAnalysis);
})->with([
    'Why is this system out of memory?',
    "What's using the most CPU on this host?",
    'Are there any OOM events on this server?',
    'Why is the load average so high on this machine?',
]);

it('does not route to CaseAnalysis when no case is open', function () {
    expect($this->router->classify('Why is this system out of memory?', false))
        ->not->toBe(AiIntent::CaseAnalysis);
});

it('keeps app actions as SosVault even with a case open and a deictic reference', function () {
    // "compare this report" references the report but is an app action, not analysis.
    expect($this->router->classify('How do I compare this report with another?', true))
        ->toBe(AiIntent::SosVault);
});

it('does not misfire CaseAnalysis on the word upload (which contains "load")', function () {
    expect($this->router->classify('How do I upload a report?', true))
        ->toBe(AiIntent::SosVault);
});

// ---------------------------------------------------------------------------
// Explicit "Label:" prefixes force the intent, overriding keyword scoring.
// ---------------------------------------------------------------------------

it('honours an explicit intent label regardless of the body keywords', function (string $message, AiIntent $expected) {
    expect($this->router->classify($message, false))->toBe($expected);
})->with([
    // "sosreport" would keyword-score toward SosCommand, but the label wins.
    ['SosVault: How do I upload a sosreport?', AiIntent::SosVault],
    ['sos-vault: where are my keys?', AiIntent::SosVault],
    ['SosCommand: how do I limit log size to 10MB?', AiIntent::SosCommand],
    ['sos: how do I obfuscate data?', AiIntent::SosCommand],
    ['Linux: how do I upload a file with scp?', AiIntent::Linux],
]);

it('honours a Case label only when a case is open', function () {
    expect($this->router->classify('Case: any problems here?', true))->toBe(AiIntent::CaseAnalysis);
    // No case open: the label is ignored and the body is keyword-classified.
    expect($this->router->classify('Case: any problems here?', false))->not->toBe(AiIntent::CaseAnalysis);
});

// ---------------------------------------------------------------------------
// Slash topic commands (/case, /sosvault, /sos, /linux) — the primary syntax.
// ---------------------------------------------------------------------------

it('honours a slash topic command regardless of the body keywords', function (string $message, AiIntent $expected) {
    expect($this->router->classify($message, false))->toBe($expected);
})->with([
    // "sosreport" keyword-scores toward SosCommand, but the /sosvault command wins.
    ['/sosvault how do I upload a sosreport?', AiIntent::SosVault],
    ['/sos how do I obfuscate data?', AiIntent::SosCommand],
    ['/linux how do I upload a file with scp?', AiIntent::Linux],
]);

it('does not mis-read /sosvault as /sos', function () {
    expect($this->router->forcedIntent('/sosvault where are my keys?'))->toBe(AiIntent::SosVault);
    expect($this->router->forcedIntent('/sos how do I run a report?'))->toBe(AiIntent::SosCommand);
});

it('honours a /case command only when a case is open', function () {
    expect($this->router->classify('/case any problems here?', true))->toBe(AiIntent::CaseAnalysis);
    expect($this->router->classify('/case any problems here?', false))->not->toBe(AiIntent::CaseAnalysis);
});

it('reports the forced intent of a slash command ignoring the case-open guard', function () {
    // forcedIntent is raw user intent — CaseAnalysis even with no case open, so the
    // widget can refuse rather than answer blind.
    expect($this->router->forcedIntent('/case what is the load?'))->toBe(AiIntent::CaseAnalysis);
    expect($this->router->forcedIntent('how do I check disk usage?'))->toBeNull();
});

it('strips a slash topic command from the message body', function () {
    expect($this->router->stripLabel('/case what is the overall system state?'))
        ->toBe('what is the overall system state?');
    expect($this->router->stripLabel('/sosvault  where are my keys?'))
        ->toBe('where are my keys?');
});

it('does not treat /sosreport as the /sos command', function () {
    // Only the exact /sos token (word-bounded) is a topic command.
    expect($this->router->forcedIntent('/sosreport something'))->toBeNull();
});

it('leaves unrecognised prefixes and mid-sentence colons alone', function () {
    // "note" is not an intent label, so this stays keyword-routed (SosVault).
    expect($this->router->classify('Note: how do I share a file in the vault?', false))
        ->toBe(AiIntent::SosVault);
});

it('strips a recognised label from the message body', function () {
    expect($this->router->stripLabel('SosCommand: how do I limit log size to 10MB?'))
        ->toBe('how do I limit log size to 10MB?');
    expect($this->router->stripLabel('sos-vault:  where are my keys?'))
        ->toBe('where are my keys?');
});

it('returns the message unchanged when there is no recognised label', function () {
    expect($this->router->stripLabel('How do I check disk usage?'))
        ->toBe('How do I check disk usage?');
    expect($this->router->stripLabel('Visit https://example.com for details'))
        ->toBe('Visit https://example.com for details');
});
