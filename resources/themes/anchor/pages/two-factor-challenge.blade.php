<?php
use App\Services\TwoFactorService;
use Livewire\Volt\Component;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware('auth');
name('two-factor.challenge');

new class extends Component
{
    public string $code = '';

    public function mount(): void
    {
        // Nothing to challenge — bounce to the normal landing flow.
        if (! auth()->user()->hasTwoFactorEnabled() || session('2fa_passed')) {
            $this->redirect('/auth/home', navigate: false);
        }
    }

    public function verify(): void
    {
        $user = auth()->user();
        $uid = $user->id;

        if (app(TwoFactorService::class)->verifyForUser($user, $this->code)) {
            session(['2fa_passed' => true]);
            addEvent((object) ['message' => 'two-factor challenge passed'], 'LOGIN', 'SUCCESS', 'ACTIVITY', 0, 0, $uid, $uid);

            $this->redirectIntended('/auth/home', navigate: false);

            return;
        }

        addEvent((object) ['message' => 'two-factor challenge failed'], 'LOGIN', 'FAILED', 'ACTIVITY', 0, 0, $uid, $uid);
        $this->addError('code', __('settings.two_factor_invalid_code'));
        $this->code = '';
    }
}

?>

<x-layouts.marketing>
    @volt('two-factor-challenge')
        <div class="flex flex-col justify-center py-2 sm:px-6 lg:px-8">
            <div class="sm:mx-auto sm:w-full sm:max-w-md sm:pt-10">
                <h2 class="sm:mt-4 text-2xl font-extrabold leading-5 text-center text-gray-800 dark:text-white">
                    {{ __('settings.two_factor_heading') }}
                </h2>
                <p class="mt-3 text-sm text-center text-gray-500 dark:text-gray-400">
                    {{ __('settings.two_factor_code_label') }}
                </p>
            </div>

            <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md">
                <div class="px-4 py-6 bg-white border shadow dark:bg-gray-800 border-gray-50 sm:rounded-lg sm:px-10">
                    <form wire:submit="verify" class="space-y-4">
                        <input wire:model="code" inputmode="numeric" autocomplete="one-time-code" autofocus
                            class="w-full text-center tracking-widest rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-white"
                            placeholder="123456" />
                        @error('code')
                            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                        <x-button type="submit" class="w-full justify-center">
                            {{ __('settings.two_factor_confirm_button') }}
                        </x-button>
                    </form>

                    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
                        @csrf
                        <button type="submit" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                            {{ __('Log out') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @endvolt
</x-layouts.marketing>
