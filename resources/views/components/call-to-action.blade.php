@props([
    'legend' => '',
    'url' => '',
])
<div class="flex self-center justify-center items-center w-full md:mb-4">
    <div
        class="flex flex-col justify-center items-center gap-1 mt-4 w-full
             md:flex-row md:items-center md:mt-0 md:w-3/5 md:gap-6 self-center
        "
    >
        <a class="w-full flex flex-col justify-between items-start grow"
            href="{{ route('register') }}"
        >
            <x-button size="lg" color="primary" class="w-full flex ">Start Free Trial</x-button>
            <div class="pl-2 py-2 text-xs text-zinc-400 text-nowrap leading-3">No credit card required</div>
        </a>

        @if(isset($legend) && !empty($legend))
        <a class="w-full flex flex-col justify-between items-start grow"
            href="{{ $url }}"
        >
            <x-button size="lg" color="gray" class="w-full flex ">
                {{ $legend }}
            </x-button>
            <div class="pl-2 py-2 text-xs text-zinc-400 text-nowrap leading-3 ">&nbsp;</div>
        </a>
        @endif

        <a class="w-full flex flex-col justify-between items-start grow"
            href="https://youtu.be/plgiVv70fJM" target="_blank"

        >
            <x-button size="lg" color="gray" class="w-full flex ">Product Tour</x-button>
            <div class="pl-2 py-2 text-xs text-zinc-400 text-nowrap leading-3 ">&nbsp;</div>
        </a>
    </div>
</div>

