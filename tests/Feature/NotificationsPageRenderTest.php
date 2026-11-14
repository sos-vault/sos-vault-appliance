<?php

/*
|--------------------------------------------------------------------------
| Notifications page tolerates varied notification payload shapes
|--------------------------------------------------------------------------
|
| Notifications reach a user from several producers, and not all of them carry
| the full ActionNotification data shape (icon/status/body/link/user). A payload
| missing the 'link' (or 'user') key used to throw `Undefined array key "link"`
| and 500 the whole page (fresh-VM report #1). The view now reads those keys
| defensively; this guards that.
|
*/

use App\Models\User;
use App\Notifications\ActionNotification;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
});

it('renders when a notification payload is missing link and user keys', function () {
    // A minimal payload (e.g. a system/parse notification) with no link/user.
    $data = (object) ['toarray' => [
        'status' => 'success',
        'body' => 'A sosreport finished unpacking.',
    ]];

    $this->user->notify(new ActionNotification($this->user, $data));

    $this->actingAs($this->user)
        ->get('/notifications')
        ->assertStatus(200)
        ->assertSee('A sosreport finished unpacking.');
});

it('still renders a fully-populated notification payload', function () {
    $data = (object) ['toarray' => [
        'icon' => "/storage/{$this->user->avatar}",
        'status' => 'info',
        'body' => 'Someone opened your shared report.',
        'link' => '/dashboard',
        'user' => ['name' => 'Jane Analyst'],
    ]];

    $this->user->notify(new ActionNotification($this->user, $data));

    $this->actingAs($this->user)
        ->get('/notifications')
        ->assertStatus(200)
        ->assertSee('Someone opened your shared report.');
});
