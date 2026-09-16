<?php
use App\Services\TwoFactorService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Livewire\Volt\Component;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware('auth');
name('settings.security');

new class extends Component implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    // Two-factor enrollment state.
    public bool $settingUp2fa = false;
    public ?string $enrollQr = null;
    public ?string $enrollSecret = null;
    public string $twoFactorCode = '';
    public ?array $recoveryCodes = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('current_password')
                    ->label(__('settings.security_current_password_label'))
                    ->autocomplete('current-password')
                    ->required()
                    ->currentPassword()
                    ->password()
                    ->revealable(),
                TextInput::make('password')
                    ->label(__('settings.security_new_password_label'))
                    ->autocomplete('new-password')
                    ->required()
                    ->minLength(4)
                    ->password()
                    ->revealable(),
                TextInput::make('password_confirmation')
                    ->label(__('settings.security_confirm_password_label'))
                    ->autocomplete('confirm-new-password')
                    ->required()
                    ->password()
                    ->revealable()
                    ->same('password'),
                // ...
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $this->validate();

        auth()->user()->forceFill([
            'password' => bcrypt($state['password']),
        ])->save();

        $uid = auth()->id() ?? 0;
        addEvent((object) ['message' => 'password changed'], 'CHG_PASS', 'SUCCESS', 'ACTIVITY', 0, 0, $uid, $uid);

        $this->form->fill();

        Notification::make()
            ->title(__('settings.security_save_success'))
            ->success()
            ->send();
    }

    // --- Two-factor authentication -------------------------------------------

    public function twoFactorEnabled(): bool
    {
        return auth()->user()->hasTwoFactorEnabled();
    }

    public function startTwoFactorSetup(): void
    {
        $svc = app(TwoFactorService::class);
        $secret = $svc->generateSecret();

        // Authoritative pending secret lives server-side until confirmed.
        session(['2fa_pending_secret' => $secret]);

        $this->enrollSecret = $secret;
        $this->enrollQr = $svc->qrCodeDataUri($svc->otpauthUri($secret, auth()->user()->email));
        $this->twoFactorCode = '';
        $this->recoveryCodes = null;
        $this->settingUp2fa = true;
    }

    public function confirmTwoFactorSetup(): void
    {
        $svc = app(TwoFactorService::class);
        $secret = (string) session('2fa_pending_secret');

        if ($secret === '' || ! $svc->verifyCode($secret, $this->twoFactorCode)) {
            Notification::make()->title(__('settings.two_factor_invalid_code'))->danger()->send();

            return;
        }

        $codes = $svc->generateRecoveryCodes();
        $svc->enable(auth()->user(), $secret, $codes);
        session()->forget('2fa_pending_secret');
        // Enrolling proves possession this session — don't immediately challenge.
        session(['2fa_passed' => true]);

        // The ENABLE_2FA audit event is emitted by TwoFactorService::enable().

        $this->reset(['settingUp2fa', 'enrollQr', 'enrollSecret', 'twoFactorCode']);
        $this->recoveryCodes = $codes;

        Notification::make()->title(__('settings.two_factor_enabled_success'))->success()->send();
    }

    public function cancelTwoFactorSetup(): void
    {
        session()->forget('2fa_pending_secret');
        $this->reset(['settingUp2fa', 'enrollQr', 'enrollSecret', 'twoFactorCode']);
    }

    public function disableTwoFactor(): void
    {
        app(TwoFactorService::class)->disable(auth()->user());

        // The DISABLE_2FA audit event is emitted by TwoFactorService::disable().

        $this->reset(['settingUp2fa', 'enrollQr', 'enrollSecret', 'twoFactorCode', 'recoveryCodes']);

        Notification::make()->title(__('settings.two_factor_disabled_success'))->success()->send();
    }
}

?>

<x-layouts.app>
    @volt('settings.security')
        <div class="relative space-y-10">
            <x-app.settings-layout
                :title="__('settings.security_title')"
                :description="__('settings.security_description')"
            >
                <form wire:submit="save" class="w-full max-w-lg">
                    {{ $this->form }}
                    <div class="w-full pt-6 text-right">
                        <x-button type="submit">{{ __('settings.save') }}</x-button>
                    </div>
                </form>

                <div class="w-full max-w-lg pt-10 mt-10 border-t border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('settings.two_factor_heading') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('settings.two_factor_description') }}</p>

                    @if (auth()->user()->hasRole('admin') && ! $this->twoFactorEnabled())
                        <div class="p-3 mt-4 text-sm rounded-lg bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                            {{ __('settings.two_factor_required_admin') }}
                        </div>
                    @endif

                    {{-- One-time recovery codes, shown immediately after activation --}}
                    @if ($recoveryCodes)
                        <div class="p-4 mt-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __('settings.two_factor_recovery_heading') }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('settings.two_factor_recovery_intro') }}</p>
                            <div class="grid grid-cols-2 gap-2 mt-3 font-mono text-sm">
                                @foreach ($recoveryCodes as $code)
                                    <code class="px-2 py-1 bg-white rounded dark:bg-gray-900">{{ $code }}</code>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($this->twoFactorEnabled())
                        <div class="mt-4">
                            <p class="text-sm text-success-600 dark:text-success-400">{{ __('settings.two_factor_status_enabled') }}</p>
                            <div class="mt-3">
                                <x-button wire:click="disableTwoFactor" color="gray" type="button">
                                    {{ __('settings.two_factor_disable_button') }}
                                </x-button>
                            </div>
                        </div>
                    @elseif ($settingUp2fa)
                        <div class="mt-4 space-y-3">
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('settings.two_factor_setup_scan') }}</p>
                            <img src="{{ $enrollQr }}" alt="2FA QR code" class="w-44 h-44 bg-white p-2 rounded" />
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('settings.two_factor_setup_manual') }}
                                <code class="px-1 font-mono select-all">{{ $enrollSecret }}</code>
                            </p>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('settings.two_factor_code_label') }}</label>
                                <input wire:model="twoFactorCode" inputmode="numeric" autocomplete="one-time-code"
                                    class="mt-1 w-40 rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-white" />
                            </div>
                            <div class="flex gap-2">
                                <x-button wire:click="confirmTwoFactorSetup" type="button">{{ __('settings.two_factor_confirm_button') }}</x-button>
                                <x-button wire:click="cancelTwoFactorSetup" color="gray" type="button">{{ __('settings.two_factor_cancel_button') }}</x-button>
                            </div>
                        </div>
                    @else
                        <div class="mt-4">
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('settings.two_factor_status_disabled') }}</p>
                            <div class="mt-3">
                                <x-button wire:click="startTwoFactorSetup" type="button">{{ __('settings.two_factor_enable_button') }}</x-button>
                            </div>
                        </div>
                    @endif
                </div>

            </x-app.settings-layout>
        </div>
    @endvolt
</x-layouts.app>
