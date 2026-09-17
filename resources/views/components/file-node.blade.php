<tr class="pl-{{ $level * 2 }}">
    <td class="px-2 py-1">
        @php
            if($node['type'] === 'd') {
                $icon = isset($expanded[$node['id']]) ? "phosphor-folder-open-duotone" : "phosphor-folder-plus-duotone";
                $color = isset($expanded[$node['id']]) ? "warning" : "primary";
            } else {
                $icon = "phosphor-file-text-duotone";
                $color = "primary";
            }
        @endphp
        <button wire:click="toggleExpand('{{ $node['id'] }}')" class="">
            <x-dynamic-component :component="$icon" class="shrink-0 w-8 h-8 text-{{ $color }}-400" />
        </button>
        {{-- str_repeat('— ', $level) }} {{ $node['name'] --}}
    </td>
    <td class="px-2 py-1">{{ $node['perms'] ?? '' }}</td>
    <td class="px-2 py-1">{{ $node['owner'] ?? '' }}</td>
    <td class="px-2 py-1">{{ $node['group'] ?? '' }}</td>
    <td class="px-2 py-1">{{ $node['size'] ?? '' }}</td>
    <td class="px-2 py-1">{{ $node['date'] ?? '' }}</td>
    <td class="px-2 py-1">{{ $node['name'] ?? '' }}</td>
</tr>

@if(($node['type'] === 'd' || $node['type'] === 'l')
    && isset($expanded[$node['id']]))
    @foreach($node['nodes'] ?? [] as $child)
        @include('components.file-node', ['node' => $child, 'level' => $level+1])
    @endforeach
@endif

