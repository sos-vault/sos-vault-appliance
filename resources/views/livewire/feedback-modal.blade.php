<?php
use App\Events\SendUserEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $open = false;

    public bool $submitted = false;

    #[Validate('required')]
    public string $feedbackType = '';

    #[Validate('required|min:3|max:120')]
    public string $subject = '';

    #[Validate('required|min:10|max:2000')]
    public string $description = '';

    #[On('open-feedback')]
    public function openModal(): void
    {
        $this->open = true;
        $this->submitted = false;
        $this->feedbackType = '';
        $this->subject = '';
        $this->description = '';
        $this->resetValidation();
    }

    public function closeModal(): void
    {
        $this->open = false;
    }

    public function submit(): void
    {
        $this->validate();

        $user = auth()->user();
        $to = config('mail.from.address', 'admin@sos-vault.com');

        $typeLabels = [
            'bug' => 'Bug Report',
            'feature' => 'Feature Request',
            'improvement' => 'Improvement',
            'other' => 'Other',
        ];

        $typeLabel = $typeLabels[$this->feedbackType] ?? $this->feedbackType;
        $subject = "[sos-vault] {$typeLabel} from {$user->name}: {$this->subject}";
        $body = "Type: {$typeLabel}\nSubject: {$this->subject}\n\n{$this->description}";

        try {
            event(new SendUserEmail([
                'type' => 'internal',
                'to' => $to,
                'from' => $user->email,
                'subject' => $subject,
                'title' => $subject,
                'name' => $user->name,
                'username' => $user->username,
                'uid' => $user->id,
                'email' => $user->email,
                'plans' => $user->role->display_name ?? '—',
                'daysleft' => $user->daysLeftOnTrial(),
                'since' => Carbon::parse($user->created_at)->toDateString(),
                'body' => $body,
                'cc' => [],
                'attachments' => [],
            ]));

            $this->submitted = true;
        } catch (Throwable $e) {
            Log::error("FeedbackModal email failed: {$e->getMessage()}");
            $this->addError('description', 'Could not send feedback right now. Please contact support@sos-vault.com directly.');
        }
    }
}
?>

<div>
@if($open)
<div
    x-data
    x-init="document.body.classList.add('overflow-hidden')"
    x-effect="if (!$wire.open) document.body.classList.remove('overflow-hidden')"
    class="fixed inset-0 z-[100] flex items-center justify-center"
>
    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-black/60" wire:click="closeModal"></div>

    {{-- Modal panel --}}
    <div class="relative z-10 w-full max-w-lg mx-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl shadow-2xl overflow-hidden">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white flex items-center gap-2.5">
                <x-phosphor-chat-teardrop-text-duotone class="w-6 h-6 text-primary-500 mr-2" />
                {{ __('nav.nav_feedback') }}
            </h2>
            <button wire:click="closeModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                <x-phosphor-x class="w-5 h-5" />
            </button>
        </div>

        @if(!$submitted)
        {{-- Subtitle --}}
        <p class="px-6 pt-4 text-sm text-zinc-500 dark:text-zinc-400">{{ __('nav.nav_feedback_subtitle') }}</p>
        @endif

        @if($submitted)
            {{-- Success state --}}
            <div class="flex flex-col items-center justify-center gap-4 px-6 py-12 text-center">
                <x-phosphor-check-circle-duotone class="w-14 h-14 text-success-500" />
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Thank you for your feedback!</h3>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">We've received your message and will get back to you if needed.</p>
                <button
                    wire:click="closeModal"
                    class="mt-2 px-5 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium transition-colors"
                >
                    Close
                </button>
            </div>
        @else
            {{-- Form --}}
            <div class="px-6 py-5 space-y-4">

                {{-- Feedback type --}}
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">Feedback Type</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach([
                            ['value' => 'bug',         'label' => 'Bug Report',       'icon' => 'phosphor-bug-duotone',          'color' => 'text-danger-500'],
                            ['value' => 'feature',     'label' => 'Feature Request',  'icon' => 'phosphor-lightbulb-duotone',    'color' => 'text-warning-500'],
                            ['value' => 'improvement', 'label' => 'Improvement',      'icon' => 'phosphor-trend-up-duotone',     'color' => 'text-success-500'],
                            ['value' => 'other',       'label' => 'Other',            'icon' => 'phosphor-question-duotone',     'color' => 'text-zinc-400'],
                        ] as $option)
                        <button
                            type="button"
                            wire:click="$set('feedbackType', '{{ $option['value'] }}')"
                            class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg border text-sm font-medium transition-all
                                {{ $feedbackType === $option['value']
                                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300'
                                    : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300 hover:border-zinc-300 dark:hover:border-zinc-600 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}"
                        >
                            <x-dynamic-component :component="$option['icon']" class="w-5 h-5 shrink-0 mr-2 {{ $option['color'] }}" />
                            {{ $option['label'] }}
                        </button>
                        @endforeach
                    </div>
                    @error('feedbackType')
                        <p class="mt-1 text-xs text-danger-500">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Subject --}}
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">Subject</label>
                    <input
                        type="text"
                        wire:model="subject"
                        placeholder="Brief summary of your feedback"
                        maxlength="120"
                        class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white px-3 py-2 text-sm placeholder:text-zinc-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent transition"
                    />
                    @error('subject')
                        <p class="mt-1 text-xs text-danger-500">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Description --}}
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">Description</label>
                    <textarea
                        wire:model="description"
                        placeholder="Please describe your feedback in detail..."
                        rows="5"
                        maxlength="2000"
                        class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white px-3 py-2 text-sm placeholder:text-zinc-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent transition resize-none"
                    ></textarea>
                    @error('description')
                        <p class="mt-1 text-xs text-danger-500">{{ $message }}</p>
                    @enderror
                </div>

            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                <button
                    type="button"
                    wire:click="closeModal"
                    class="px-4 py-2 rounded-lg border border-zinc-200 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300 text-sm font-medium hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    class="flex items-center gap-2 px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm font-medium transition-colors"
                >
                    <span wire:loading.remove wire:target="submit">
                        <x-phosphor-paper-plane-tilt-duotone class="w-4 h-4 inline -mt-0.5" />
                    </span>
                    <span wire:loading wire:target="submit">
                        <x-phosphor-circle-notch class="w-4 h-4 inline animate-spin -mt-0.5" />
                    </span>
                    Submit Feedback
                </button>
            </div>
        @endif

    </div>
</div>
@endif
</div>
