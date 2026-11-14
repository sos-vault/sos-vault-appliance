<?php

use App\Livewire\ChatWidget;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Livewire\Livewire;

/**
 * Mil chat output is markdown-rendered from model replies, which are influenced
 * by untrusted sosreport content (prompt injection). The render must escape raw
 * HTML AND strip unsafe link schemes so an injected reply can't emit a clickable
 * javascript:/data: XSS link.
 */
beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function milAssistantMessage(string $content): array
{
    return [[
        'role' => 'assistant',
        'content' => $content,
        'sysevent_id' => 1,
        'quality' => null,
        'time' => '12:00',
    ]];
}

it('strips a javascript: link from an assistant reply (no clickable XSS)', function () {
    Livewire::test(ChatWidget::class, ['detached' => true])
        ->set('messages', milAssistantMessage('Please [click me](javascript:alert(document.cookie)) now.'))
        ->assertDontSee('href="javascript:', false)
        ->assertSee('click me');
});

it('strips a data: link from an assistant reply', function () {
    Livewire::test(ChatWidget::class, ['detached' => true])
        ->set('messages', milAssistantMessage('[x](data:text/html,<script>alert(1)</script>)'))
        ->assertDontSee('href="data:', false);
});

it('escapes raw HTML in an assistant reply', function () {
    Livewire::test(ChatWidget::class, ['detached' => true])
        ->set('messages', milAssistantMessage('<img src=x onerror=alert(1)>'))
        ->assertDontSee('<img src=x', false);
});
