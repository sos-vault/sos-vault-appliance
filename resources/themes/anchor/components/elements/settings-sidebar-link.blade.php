<a {{ $attributes }} href="{{ $href }}"
    class="text-sm font-medium
    @if($href == RalphJSmit\Livewire\Urls\Facades\Url::current())
        {{ 'text-primary-700 dark:text-primary-500
        border-transparent
        bg-white dark:bg-zinc-800/60
        shadow-xs'
        }}
    @else
        {{ 'border-transparent' }}
    @endif
    transition-colors border rounded-lg w-full h-auto hover:bg-zinc-100
    dark:hover:bg-zinc-800/60 hover:text-zinc-900 dark:hover:text-zinc-100
    relative flex justify-start items-center space-x-2 px-2.5 py-2
    overflow-hidden group-hover:autoflow-auto items
    ">

    <x-dynamic-component :component="$icon" x-data="" class="shrink-0 w-6 h-6" />

    <span class="shrink-0 ease-out duration-50 ">{{ $slot }}</span>
</a>
