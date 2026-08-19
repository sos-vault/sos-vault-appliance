<?php

use App\Contracts\AiChatServiceContract;
use App\Exceptions\AiProviderException;
use App\Exceptions\AiRateLimitException;
use App\Livewire\ChatWidget;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\ModelProvisionService;
use Database\Seeders\RolesTableSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();

    // Default: service returns a canned response
    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldReceive('chat')->andReturn('The system uptime is 5 days.');
        $mock->shouldReceive('providerName')->andReturn('local/qwen2.5-3b-instruct');
    });
});

it('renders without errors for authenticated user', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->assertSet('isOpen', false)
        ->assertSet('thinking', false)
        ->assertSet('did', null)
        ->assertStatus(200);
});

it('toggles open state', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->assertSet('isOpen', false)
        ->call('toggle')
        ->assertSet('isOpen', true)
        ->call('toggle')
        ->assertSet('isOpen', false);
});

it('sets case context via chat-set-case event', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->dispatch('chat-set-case', did: 42, cid: 7)
        ->assertSet('did', 42)
        ->assertSet('cid', 7);
});

it('persists the case to the session when set via chat-set-case', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->dispatch('chat-set-case', did: 42, cid: 7);

    expect(session('mil_open_case'))->toBe(['did' => 42, 'cid' => 7]);
});

it('adopts the open case from the session on mount (any tool window/page)', function () {
    $this->actingAs($this->user);

    // Simulate the sosbrowser having recorded the open case; a widget mounted
    // in a different tool window must pick it up without any dispatch.
    session(['mil_open_case' => ['did' => 99, 'cid' => 3]]);

    Livewire::test(ChatWidget::class)
        ->assertSet('did', 99)
        ->assertSet('cid', 3);
});

it('mounts with no case when the session has none', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->assertSet('did', null)
        ->assertSet('cid', null);
});

it('sends a message and shows the response', function () {
    $this->actingAs($this->user);

    $test = Livewire::test(ChatWidget::class)
        ->set('input', 'What is the system uptime?')
        ->call('send');

    $test->assertSet('thinking', false)
        ->assertSet('input', '');

    $messages = $test->get('messages');
    $roles = array_column($messages, 'role');
    expect($roles)->toContain('user')
        ->toContain('assistant');
});

it('persists user and assistant messages to the database', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->set('input', 'What is the load average?')
        ->call('send');

    $session = ChatSession::where('user_id', $this->user->id)->first();
    expect($session)->not->toBeNull();

    $messages = $session->messages()->get();
    expect($messages)->toHaveCount(2);
    expect($messages->where('role', 'user')->first()->content)->toBe('What is the load average?');
    expect($messages->where('role', 'assistant')->first()->content)->toBe('The system uptime is 5 days.');
});

it('clears history and deletes database messages', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->set('input', 'Hello')
        ->call('send')
        ->call('clearHistory')
        ->assertSet('messages', []);

    $session = ChatSession::where('user_id', $this->user->id)->first();
    expect($session?->messages()->count())->toBe(0);
});

it('shows a friendly message when the AI provider is unavailable', function () {
    $this->actingAs($this->user);

    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldReceive('chat')->andThrow(new AiProviderException('connection refused'));
        $mock->shouldReceive('providerName')->andReturn('local/model');
    });

    $test = Livewire::test(ChatWidget::class)
        ->set('input', 'What is the kernel version?')
        ->call('send');

    $messages = $test->get('messages');
    $last = end($messages);
    expect($last['content'])->toContain('unavailable');
});

it('shows a rate limit message when rate limit is exceeded', function () {
    $this->actingAs($this->user);

    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldReceive('chat')->andThrow(new AiRateLimitException('Too many messages.'));
        $mock->shouldReceive('providerName')->andReturn('local/model');
    });

    $test = Livewire::test(ChatWidget::class)
        ->set('input', 'What is the load average?')
        ->call('send');

    $messages = $test->get('messages');
    $last = end($messages);
    expect($last['content'])->toBe('Too many messages.');
});

it('does not call the AI service or persist messages for empty input', function () {
    $this->actingAs($this->user);

    $called = false;
    $this->mock(AiChatServiceContract::class, function ($mock) use (&$called) {
        $mock->shouldNotReceive('chat');
        $mock->shouldReceive('providerName')->andReturn('local/model');
    });

    Livewire::test(ChatWidget::class)
        ->set('input', '   ')
        ->call('send');

    expect(ChatMessage::where('role', 'user')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Local-model-missing steer (appliance): tell the user the assistant isn't
// ready and who can enable it, instead of silently failing.
// ---------------------------------------------------------------------------

it('shows a steer banner when the local model is not downloaded', function () {
    config(['ai.provider' => 'local']);

    $mock = Mockery::mock(ModelProvisionService::class);
    $mock->shouldReceive('isInstalled')->andReturn(false);
    app()->instance(ModelProvisionService::class, $mock);

    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->assertSee('Software Updates')
        ->assertSee('Download AI model');
});

it('shows no steer banner when the local model is installed', function () {
    config(['ai.provider' => 'local']);

    $mock = Mockery::mock(ModelProvisionService::class);
    $mock->shouldReceive('isInstalled')->andReturn(true);
    app()->instance(ModelProvisionService::class, $mock);

    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)->assertSet('assistantNotice', '');
});

it('shows no steer banner when a cloud provider is configured', function () {
    config(['ai.provider' => 'openai']);

    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)->assertSet('assistantNotice', '');
});

// ---------------------------------------------------------------------------
// Discoverability: starter for vague openers, prefixes in /help.
// ---------------------------------------------------------------------------

it('answers a vague opener with a concrete starter instead of calling the LLM', function () {
    $this->actingAs($this->user);

    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldNotReceive('chat');
        $mock->shouldReceive('providerName')->andReturn('local/model');
    });

    $test = Livewire::test(ChatWidget::class)
        ->set('input', 'what shall I do now?')
        ->call('send');

    $messages = $test->get('messages');
    $last = end($messages);
    expect($last['content'])->toContain('/sosvault')->toContain('sos-vault');
});

it('does not treat a real question as a vague opener', function () {
    $this->actingAs($this->user);

    // beforeEach mock returns a canned answer; a real question must reach it.
    Livewire::test(ChatWidget::class)
        ->set('input', 'How do I upload a sosreport?')
        ->call('send')
        ->assertSee('The system uptime is 5 days.', false);
});

it('renders a drag handle so the chat panel is resizable', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->assertSeeHtml('cursor-nwse-resize')
        ->assertSeeHtml('startResize');
});

// ---------------------------------------------------------------------------
// Detached pop-out window (Feature F)
// ---------------------------------------------------------------------------

it('mounts the detached pop-out expanded and flagged', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class, ['detached' => true])
        ->assertSet('detached', true)
        ->assertSet('isOpen', true);
});

it('offers a detach button in the floating widget', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->assertSeeHtml('Open in a separate window');
});

it('hides the detach button and floating trigger inside the detached window', function () {
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class, ['detached' => true])
        ->assertDontSeeHtml('Open in a separate window')
        ->assertDontSeeHtml('Open Mil AI Assistant');
});

it('requires authentication for the detached Mil window', function () {
    $this->get('/mil')->assertRedirect();
});

it('renders the detached Mil window for an authenticated user', function () {
    $this->actingAs($this->user);

    $this->get('/mil')->assertOk()->assertSee('Mil');
});

it('loads the Livewire runtime in the detached Mil window', function () {
    // Regression: the pop-out rendered but had no Livewire/Alpine (the theme head
    // ships only styles + a Vite bundle that does not start Alpine), so the send
    // button and Enter key were dead. The scripts must be present.
    $this->actingAs($this->user);

    $this->get('/mil')->assertOk()->assertSee('/livewire/livewire', false);
});

it('documents the slash topic commands in /help', function () {
    $this->actingAs($this->user);

    $test = Livewire::test(ChatWidget::class)
        ->set('input', '/help')
        ->call('send');

    $messages = $test->get('messages');
    $last = end($messages);
    expect($last['content'])
        ->toContain('/case')
        ->toContain('/sosvault')
        ->toContain('/sos')
        ->toContain('/linux');
});

// ---------------------------------------------------------------------------
// Topic commands (/case …) route a normal AI question; /case with no open case
// is refused; SaaS-only contact commands are gated off the self-hosted build.
// ---------------------------------------------------------------------------

it('refuses a /case question when no case is open instead of calling the LLM', function () {
    $this->actingAs($this->user);

    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldNotReceive('chat');
        $mock->shouldReceive('providerName')->andReturn('local/model');
    });

    $test = Livewire::test(ChatWidget::class)
        ->assertSet('did', null)
        ->set('input', '/case what is the system load?')
        ->call('send');

    $messages = $test->get('messages');
    $last = end($messages);
    expect($last['content'])->toContain('Browse SOS Report');
});

it('answers a /case question through the LLM when a case is open on a cloud provider', function () {
    // A capable cloud provider (case analysis enabled) → the question reaches the LLM.
    config(['ai.provider' => 'openai']);

    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->dispatch('chat-set-case', did: 42, cid: 7)
        ->set('input', '/case what is the system load?')
        ->call('send')
        ->assertSee('The system uptime is 5 days.', false);
});

it('steers a /case question to a cloud LLM when the configured model is local', function () {
    // The local model can't analyse sosreports (case_analysis_enabled=false), so a
    // /case question with a case open must NOT reach the LLM — it gets an immediate steer.
    config(['ai.provider' => 'local']);

    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldNotReceive('chat');
        $mock->shouldReceive('providerName')->andReturn('local/qwen2.5-3b-instruct');
    });

    $this->actingAs($this->user);

    $test = Livewire::test(ChatWidget::class)
        ->dispatch('chat-set-case', did: 42, cid: 7)
        ->set('input', '/case what is the overall state of the box?')
        ->call('send');

    $messages = $test->get('messages');
    $last = end($messages);
    expect($last['content'])->toContain('Manage Settings → AI Assistant');
});

it('nudges when a topic command is sent with no question', function () {
    $this->actingAs($this->user);

    $this->mock(AiChatServiceContract::class, function ($mock) {
        $mock->shouldNotReceive('chat');
        $mock->shouldReceive('providerName')->andReturn('local/model');
    });

    $test = Livewire::test(ChatWidget::class)
        ->set('input', '/linux')
        ->call('send');

    $messages = $test->get('messages');
    $last = end($messages);
    expect($last['content'])->toContain('Add your question');
});

it('gates /suggest on the self-hosted build', function () {
    config(['product.type' => 'appliance']);
    $this->actingAs($this->user);

    $test = Livewire::test(ChatWidget::class)
        ->set('input', '/suggest')
        ->call('send')
        ->assertSet('pendingCommand', null);

    $messages = $test->get('messages');
    $last = end($messages);
    expect($last['content'])->toContain('self-hosted edition');
});

it('allows /suggest on the hosted service', function () {
    config(['product.type' => 'saas']);
    $this->actingAs($this->user);

    Livewire::test(ChatWidget::class)
        ->set('input', '/suggest')
        ->call('send')
        ->assertSet('pendingCommand', 'suggest');
});
