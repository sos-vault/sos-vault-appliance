<x-app.container>
    <x-filament::modal
        id="progress-modal"
        alignment="left"
        width="3xl"
        :close-by-clicking-away="false"
        :close-by-escaping="false"
        :close-button="true"
    >
        <x-slot name="heading">{{ __('vault.progress_unpacking') }}</x-slot>

        <div x-data="{ showProgress: @entangle('isProgress') }">
            <template x-if="showProgress">
                <div wire:poll.1s="poll">
                    <div
                        x-data="{
                            currentVal: $wire.entangle('currentVal'),
                            minVal: 0,
                            maxVal: 100,
                            calcPercentage(min, max, val){
                                return (((val-min)/(max-min))*100).toFixed(0)
                            }
                        }"
                        class="w-full"
                    >

                        <div class="mt-1 mb-1 flex items-end justify-between gap-2 text-zinc-600 dark:text-zinc-200">
                            <span>{{ $currentPhase }}</span>
                            <span x-text="`${calcPercentage(minVal, maxVal, currentVal)}%`"></span>
                        </div>

                        <div
                            class="flex h-2.5 w-full overflow-hidden rounded-radius bg-zinc-200 dark:bg-zinc-800"
                            role="progressbar"
                            aria-label="default progress bar"
                            x-bind:aria-valuenow="currentVal"
                            x-bind:aria-valuemin="minVal"
                            x-bind:aria-valuemax="maxVal"
                        >
                            <div
                                class="h-full rounded-radius bg-green-600 dark:bg-green-800"
                                x-bind:style="`width: ${calcPercentage(minVal, maxVal, currentVal)}%`"
                            >
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </x-filament::modal>
</x-app.container>
