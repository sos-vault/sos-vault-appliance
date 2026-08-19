<div wire:ignore x-show="billing_cycle_available=='both'"
    x-init="
        setTimeout(function(){ 
            toggleRepositionMarker($refs.monthly); 
            $refs.marker.classList.remove('opacity-0');
            setTimeout(function(){ 
                $refs.marker.classList.add('duration-300', 'ease-out');
            }, 10); 
        }, 1);
    "
    @reposition-interval-marker.window="toggleRepositionMarker($refs.monthly);"
    class="relative w-40 mb-5"
    x-cloak>
    <div x-ref="toggleButtons" class="relative inline-grid items-center justify-center w-full h-10 grid-cols-2 p-1 bg-white rounded-full shadow-sm select-none dark:bg-neutral-800 ring-1 ring-gray-200 dark:ring-neutral-700">
        <button x-ref="monthly" @click="toggleButtonClicked($el, 'month');" type="button"
            :class="{ 'text-white' : billing_cycle_selected == 'month', 'text-gray-500 dark:text-neutral-400' : billing_cycle_selected != 'month' }"
            class="relative z-20 inline-flex items-center justify-center w-full h-8 px-3 text-xs font-semibold transition-all cursor-pointer whitespace-nowrap">Monthly</button>
        <button x-ref="yearly" @click="toggleButtonClicked($el, 'year');" type="button" 
            :class="{ 'text-white' : billing_cycle_selected == 'year', 'text-gray-500 dark:text-neutral-400' : billing_cycle_selected != 'year' }"
            class="relative z-20 inline-flex items-center justify-center w-full h-8 px-3 text-xs font-semibold transition-all rounded-md cursor-pointer whitespace-nowrap">Yearly</button>
        <div x-ref="marker" class="absolute left-0 z-10 w-1/2 h-full opacity-0" x-cloak>
            <div @class([
                'w-full h-full rounded-full shadow-sm',
                'bg-gray-900' => config('wave.billing_color') == 'black',
                'bg-gray-200' => config('wave.billing_color') == 'white',
                'bg-red-500' => config('wave.billing_color') == 'red',
                'bg-green-600' => config('wave.billing_color') == 'green',
                'bg-blue-600' => config('wave.billing_color') == 'blue',
                'bg-yellow-300' => config('wave.billing_color') == 'yellow',
                'bg-orange-500' => config('wave.billing_color') == 'orange',
                'bg-pink-500' => config('wave.billing_color') == 'pink',
                'bg-purple-600' => config('wave.billing_color') == 'purple',
                'bg-primary-600' => config('wave.billing_color') == 'primary',
            ])></div>
        </div>
    </div>
</div>