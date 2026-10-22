<div class="w-full">
    <div class="grid grid-cols-2 gap-4 mb-4 md:grid-cols-4">
        @foreach($this->scores() as $score)
            <div class="flex flex-col items-center justify-center rounded-lg border border-gray-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $score['label'] }}</span>
                <span @class([
                    'text-2xl font-bold',
                    'text-success-600 dark:text-success-400' => $score['color'] === 'success',
                    'text-warning-600 dark:text-warning-400' => $score['color'] === 'warning',
                    'text-orange-600 dark:text-orange-400' => $score['color'] === 'orange',
                    'text-danger-600 dark:text-danger-400' => $score['color'] === 'danger',
                    'text-gray-400 dark:text-gray-500' => $score['color'] === 'gray',
                ])>
                    {{ $score['score'] !== null ? number_format($score['score'], 1) . '%' : '—' }}
                </span>
            </div>
        @endforeach
    </div>

    {{ $this->table }}

    <x-filament-actions::modals />
</div>
