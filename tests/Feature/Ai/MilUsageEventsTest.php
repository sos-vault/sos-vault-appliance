<?php

use App\Contracts\AiChatServiceContract;
use App\Exceptions\AiProviderException;
use App\Livewire\ChatWidget;
use App\Models\ChatMessage;
use App\Models\Sysevent;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Livewire\Livewire;

// Feature A: every Mil turn records a BOT_* usage event (type by query kind,
// status TIMEDOUT/FAILED/SUCCESS, response time + tool/file + rateable quality
// in the JSON payload) so the admin can measure usage and response times.

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();

    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldReceive('chat')->andReturn('A symlink is a pointer to another file.');
        $mock->shouldReceive('providerName')->andReturn('openai/gpt-4o');
    });

    $this->actingAs($this->user);
});

it('records a BOT_ event by query kind, with timing and null quality, linked to the reply', function () {
    Livewire::test(ChatWidget::class)
        ->set('input', 'Linux: what is a symlink?')
        ->call('send');

    $event = Sysevent::where('type', 'BOT_LINUX')->first();
    expect($event)->not->toBeNull();
    expect($event->status)->toBe('SUCCESS');
    expect((int) $event->owner)->toBe($this->user->id);

    $payload = json_decode($event->payload, true);
    expect($payload)->toHaveKeys(['duration_ms', 'quality', 'provider', 'tool', 'fid', 'question']);
    expect($payload['quality'])->toBeNull();
    expect($payload['duration_ms'])->toBeInt();
    expect($payload['provider'])->toBe('openai/gpt-4o');

    // The assistant reply is linked to its event so a later 👍/👎 can rate it.
    $reply = ChatMessage::where('role', 'assistant')->latest('id')->first();
    expect($reply->sysevent_id)->toBe($event->id);
});

it('labels the event BOT_CASE and captures the tool/file when a case is open', function () {
    session(['mil_open_case' => ['did' => 5, 'cid' => 9, 'tool' => 'Summary', 'fid' => 4821]]);

    Livewire::test(ChatWidget::class)
        ->set('input', 'Case: why is memory usage so high?')
        ->call('send');

    $event = Sysevent::where('type', 'BOT_CASE')->first();
    expect($event)->not->toBeNull();
    expect((int) $event->case_id)->toBe(9);

    $payload = json_decode($event->payload, true);
    expect($payload['tool'])->toBe('Summary');
    expect($payload['fid'])->toBe(4821);
});

it('records BOT_GENERIC for a vague opener without calling the LLM', function () {
    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldNotReceive('chat');
        $mock->shouldReceive('providerName')->andReturn('openai/gpt-4o');
    });

    Livewire::test(ChatWidget::class)
        ->set('input', 'hi')
        ->call('send');

    $event = Sysevent::where('type', 'BOT_GENERIC')->first();
    expect($event)->not->toBeNull();
    expect($event->status)->toBe('SUCCESS');
});

it('records a FAILED event when the provider errors', function () {
    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldReceive('chat')->andThrow(new AiProviderException('connection refused'));
        $mock->shouldReceive('providerName')->andReturn('openai/gpt-4o');
    });

    Livewire::test(ChatWidget::class)
        ->set('input', 'Linux: what is a socket?')
        ->call('send');

    expect(Sysevent::where('type', 'BOT_LINUX')->where('status', 'FAILED')->exists())->toBeTrue();
});

it('records a TIMEDOUT event when the provider times out', function () {
    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldReceive('chat')->andThrow(
            new AiProviderException('cURL error 28: Operation timed out after 60000 ms')
        );
        $mock->shouldReceive('providerName')->andReturn('openai/gpt-4o');
    });

    Livewire::test(ChatWidget::class)
        ->set('input', 'Linux: what is a socket?')
        ->call('send');

    expect(Sysevent::where('type', 'BOT_LINUX')->where('status', 'TIMEDOUT')->exists())->toBeTrue();
});

// Feature B: a 👍/👎 on a reply writes GOOD/BAD into its linked event's quality.

it('writes GOOD/BAD into the linked event when a reply is rated', function () {
    $test = Livewire::test(ChatWidget::class)
        ->set('input', 'Linux: what is a symlink?')
        ->call('send');

    $index = collect($test->get('messages'))->search(fn ($m) => $m['role'] === 'assistant');

    $test->call('rateMessage', $index, 'GOOD');

    $event = Sysevent::where('type', 'BOT_LINUX')->first();
    expect(json_decode($event->payload, true)['quality'])->toBe('GOOD');
    expect($test->get('messages')[$index]['quality'])->toBe('GOOD');
});

it('clears the rating when the same thumb is clicked again', function () {
    $test = Livewire::test(ChatWidget::class)
        ->set('input', 'Linux: what is a symlink?')
        ->call('send');

    $index = collect($test->get('messages'))->search(fn ($m) => $m['role'] === 'assistant');

    $test->call('rateMessage', $index, 'BAD')
        ->call('rateMessage', $index, 'BAD');

    $event = Sysevent::where('type', 'BOT_LINUX')->first();
    expect(json_decode($event->payload, true)['quality'])->toBeNull();
    expect($test->get('messages')[$index]['quality'])->toBeNull();
});

it('ignores a rating for a reply with no linked event', function () {
    // A vague opener answers without an LLM turn; its bot message has no event.
    $test = Livewire::test(ChatWidget::class)
        ->set('input', 'hi')
        ->call('send');

    $index = collect($test->get('messages'))->search(fn ($m) => $m['role'] === 'assistant');

    // Must not throw or alter the (unlinked) generic event's null quality.
    $test->call('rateMessage', $index, 'GOOD');

    $event = Sysevent::where('type', 'BOT_GENERIC')->first();
    expect(json_decode($event->payload, true)['quality'])->toBeNull();
});
