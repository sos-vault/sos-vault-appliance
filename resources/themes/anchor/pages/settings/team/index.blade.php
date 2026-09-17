<?php

use App\Models\Group;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Livewire\Volt\Component;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware(['auth', 'team_manager']);
name('settings.team');

new class extends Component implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    public bool $showAddForm = false;

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function getGroup(): Group
    {
        return Group::where('owner_id', auth()->id())->firstOrFail();
    }

    public function addMember(): void
    {
        $group = $this->getGroup();

        if ($group->isFull()) {
            Notification::make()
                ->title(__('settings.team_member_limit_reached'))
                ->danger()
                ->send();

            return;
        }

        $data = $this->form->getState();

        $member = User::create([
            'name'              => $data['name'],
            'username'          => $data['username'],
            'email'             => $data['email'],
            'password'          => bcrypt($data['password']),
            'avatar'            => 'avatars/default.png',
            'group_id'          => $group->id,
            'verified'          => 1,
            'email_verified_at' => now(),
        ]);

        $roleName = $group->max_members === 20 ? 'Enterprise' : 'Team';
        $member->syncRoles([$roleName]);

        $payload = (object) [
            'message' => "new team member created: {$data['email']}",
        ];
        addEvent($payload, 'ADD_USER', 'SUCCESS', 'ACTIVITY', 0, 0, $member->id, $member->id);

        $this->form->fill();
        $this->showAddForm = false;

        Notification::make()
            ->title(__('settings.team_member_added'))
            ->success()
            ->send();
    }

    public function transferManager(int $userId): void
    {
        $group = $this->getGroup();

        $newManager = User::where('id', $userId)
            ->where('group_id', $group->id)
            ->where('id', '!=', $group->owner_id)
            ->firstOrFail();

        $group->update(['owner_id' => $newManager->id]);

        Notification::make()
            ->title(__('settings.team_manager_transferred', ['name' => $newManager->name]))
            ->success()
            ->send();
    }

    public function deleteMember(int $userId): void
    {
        if ($userId === $this->getGroup()->owner_id) {
            Notification::make()
                ->title(__('settings.team_cannot_delete_manager'))
                ->danger()
                ->send();

            return;
        }

        User::where('id', $userId)
            ->where('group_id', $this->getGroup()->id)
            ->firstOrFail()
            ->delete();

        Notification::make()
            ->title(__('settings.team_member_deleted'))
            ->success()
            ->send();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('settings.profile_name_label'))
                    ->required(),
                TextInput::make('username')
                    ->label(__('settings.team_username_label'))
                    ->required(),
                TextInput::make('email')
                    ->email()
                    ->label(__('settings.profile_email_label'))
                    ->required(),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->label(__('settings.security_new_password_label'))
                    ->minLength((int) setting('auth.min_password_length', 12))
                    ->rules(['regex:/^(?=(?:.*[A-Z]){2,})(?=(?:.*[a-z]){2,})(?=(?:.*\d){2,})(?=(?:.*[^A-Za-z0-9]){2,}).{12,}$/'])
                    ->hint(__('settings.team_password_hint'))
                    ->required(),
            ])
            ->columns(2)
            ->statePath('data');
    }
}

?>

<x-layouts.app>
    @volt('settings.team')
        <div class="relative">
            <x-app.settings-layout
                :title="__('settings.team_title')"
                :description="__('settings.team_description')"
            >
                {{-- Member count badge --}}
                @php $group = $this->getGroup(); @endphp
                <div class="flex items-center justify-between mb-4">
                    <span class="text-sm text-slate-500 dark:text-zinc-400">
                        {{ $group->members()->count() }} / {{ $group->max_members - 1 }} {{ __('settings.team_members_used') }}
                    </span>
                    <x-button wire:click="$toggle('showAddForm')" color="primary" size="sm">
                        {{ __('settings.team_add_member') }}
                    </x-button>
                </div>

                {{-- Add member form --}}
                @if($showAddForm)
                    <div class="mb-6 p-4 rounded-lg border border-slate-200 dark:border-zinc-700">
                        {{ $this->form }}
                        <div class="flex justify-end mt-4 gap-2">
                            <x-button wire:click="$set('showAddForm', false)" color="gray" size="sm">
                                {{ __('settings.itsm_action_clear') }}
                            </x-button>
                            <x-button wire:click="addMember" color="primary" size="sm">
                                {{ __('settings.team_add_member') }}
                            </x-button>
                        </div>
                    </div>
                @endif

                {{-- Members table --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-slate-600 dark:text-zinc-300">
                        <thead class="text-xs uppercase text-slate-400 dark:text-zinc-500 border-b border-slate-200 dark:border-zinc-700">
                            <tr>
                                <th class="py-2 pr-4">{{ __('settings.profile_name_label') }}</th>
                                <th class="py-2 pr-4">{{ __('settings.profile_email_label') }}</th>
                                <th class="py-2 pr-4">{{ __('settings.team_role_label') }}</th>
                                <th class="py-2 pr-4">{{ __('settings.team_created_label') }}</th>
                                <th class="py-2">{{ __('settings.team_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($group->members as $member)
                                <tr class="border-b border-slate-100 dark:border-zinc-800">
                                    <td class="py-2 pr-4">{{ $member->name }}</td>
                                    <td class="py-2 pr-4">{{ $member->email }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200">
                                            {{ $member->roles->first()?->name ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4">{{ $member->created_at->toDateString() }}</td>
                                    <td class="py-2">
                                        @if($member->id !== $group->owner_id)
                                            <div class="flex items-center gap-3">
                                                <div x-data="{ confirm: false }">
                                                    <button x-show="!confirm" @click="confirm = true"
                                                        class="text-blue-500 hover:text-blue-700 text-xs font-medium">
                                                        {{ __('settings.team_make_manager') }}
                                                    </button>
                                                    <div x-show="confirm" class="flex items-center gap-2">
                                                        <span class="text-xs text-slate-500">{{ __('settings.team_make_manager_confirm') }}</span>
                                                        <button @click="confirm = false" class="text-xs text-slate-400 hover:text-slate-600">{{ __('settings.itsm_action_clear') }}</button>
                                                        <button wire:click="transferManager({{ $member->id }})" class="text-xs text-blue-500 hover:text-blue-700 font-medium">{{ __('settings.team_make_manager') }}</button>
                                                    </div>
                                                </div>
                                                <div x-data="{ confirm: false }">
                                                    <button x-show="!confirm" @click="confirm = true"
                                                        class="text-red-500 hover:text-red-700 text-xs font-medium">
                                                        {{ __('settings.team_delete') }}
                                                    </button>
                                                    <div x-show="confirm" class="flex items-center gap-2">
                                                        <span class="text-xs text-slate-500">{{ __('settings.team_delete_confirm') }}</span>
                                                        <button @click="confirm = false" class="text-xs text-slate-400 hover:text-slate-600">{{ __('settings.itsm_action_clear') }}</button>
                                                        <button wire:click="deleteMember({{ $member->id }})" class="text-xs text-red-500 hover:text-red-700 font-medium">{{ __('settings.team_delete') }}</button>
                                                    </div>
                                                </div>
                                            </div>
                                        @else
                                            <span class="text-xs text-slate-400 dark:text-zinc-500">{{ __('settings.team_manager_label') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-6 text-center text-slate-400 dark:text-zinc-500">
                                        {{ __('settings.team_no_members') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            </x-app.settings-layout>
        </div>
    @endvolt
</x-layouts.app>
