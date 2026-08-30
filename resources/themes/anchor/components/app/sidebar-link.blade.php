@props([
    'href' => '',
    'icon' => 'phosphor-house-duotone',
    'active' => false,
    'hideUntilGroupHover' => true,
    'target' => '_self',
    'ajax' => true,
    'usage' => '',
    'color' => '',
])

@php
    $isActive = filter_var($active, FILTER_VALIDATE_BOOLEAN);
    $notifications_count = auth()->user()->unreadNotifications->count();
    $announcements_count = App\Models\Announcement::whereDoesntHave('users', function ($query) {
        $query->where('users.id', auth()->id());
    })->latest('created_at')->count();
    $usages = ['notifications','announcements'];
@endphp

<a {{ $attributes }} href="{{ $href }}"
    @if((($href ?? false) && $target == '_self') && $ajax)
        wire:navigate
    @else
        @if($ajax)
            target="_blank"
        @endif
    @endif

    class="text-sm font-medium

    @if($isActive)
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

    @if($icon && $slot)
    <x-dynamic-component :component="$icon" x-data="{tooltip: '{!! $slot !!}', get getTooltip() {const sdc = localStorage.getItem('sidebarColapsed'); return sdc ? this.tooltip : '' }}" x-tooltip.placement.right="getTooltip" class="shrink-0 w-6 h-6 text-{{ $color }}-400" />
    @endif

    @if(in_array($usage, $usages))
        @if($announcements_count > 0 && $usage == "announcements")
            <span id="announcement-count" class="absolute top-0 left-0 flex items-center justify-center w-4 h-4 text-xs text-blue-100 bg-blue-500 rounded-full">{{ $announcements_count }}</span>
        @elseif($notifications_count > 0 && $usage == "notifications")
            <span id="notification-count" class="absolute top-0 left-0 flex items-center justify-center w-4 h-4 text-xs text-red-100 bg-red-500 rounded-full">{{ $notifications_count }}</span>
        @endif
    @endif

    <span class="sbcolapse shrink-0 ease-out duration-50">{{ $slot }}</span>
</a>

<div class="hidden
    text-info-800
    text-info-700
    text-info-600
    text-info-500
    text-info-400
    text-info-300
    text-info-200
    text-info-100

    text-primary-800
    text-primary-700
    text-primary-600
    text-primary-500
    text-primary-400
    text-primary-300
    text-primary-200
    text-primary-100

    text-danger-800
    text-danger-700
    text-danger-600
    text-danger-500
    text-danger-400
    text-danger-300
    text-danger-200
    text-danger-100

    text-warning-800
    text-warning-700
    text-warning-600
    text-warning-500
    text-warning-400
    text-warning-300
    text-warning-200
    text-warning-100

"></div>

