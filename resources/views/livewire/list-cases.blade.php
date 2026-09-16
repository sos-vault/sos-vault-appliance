<div>
    @php
        $color = [
            'OPEN' => 'primary',
            'WAITCUST' => 'info',
            'CLOSED' => 'danger',
            'REOPEN' => 'primary',
            'BLOCKED' => 'warning',
            'SOLVED' => 'danger',
            'DONE' => 'zinc',
            'WAIT' => 'info',
        ];
    @endphp

    @if($type == "tool-control")
        <x-app.sidebar-dropdown
            text="{{ __('vault.case_choose_case') }}"
            icon="phosphor-tree-view-duotone"
            id="tool_case_dropdown"
            :active="(Request::is('tool_case_dropdown'))"
            :open="0"
        >

        @foreach( \App\Models\SupportCase::where('group', auth()->user()->group_id ?? auth()->id())->get() as $case)
            <x-app.sidebar-link
                icon="{{ $case->os_icon }}"
                color="{{ $color[$case->status] }}"
                href="/sosbrowser/{{ $case->id }}"
                :active="true"
            >
                {{ __('vault.case_label', ['case' => $case->case]) }}
            </x-app.sidebar-link>
        @endforeach

        </x-app.sidebar-dropdown>
    @endif

    @if($type == "sidebar")
        <x-app.sidebar-dropdown
            text="{{ __('vault.case_browse_sos') }}"
            icon="phosphor-tree-view-duotone"
            id="browser_dropdown"
            :active="(Request::is('browser_dropdown'))"
            :open="0"
        >

        @foreach( \App\Models\SupportCase::where('group', auth()->user()->group_id ?? auth()->id())->get() as $case)
            <x-app.sidebar-link
                icon="{{ $case->os_icon }}"
                color="{{ $color[$case->status] }}"
                href="/sosbrowser/{{ $case->id }}"
                :active="true"
            >
                {{ __('vault.case_label', ['case' => $case->case]) }}
            </x-app.sidebar-link>
        @endforeach

        </x-app.sidebar-dropdown>
    @endif
</div>
