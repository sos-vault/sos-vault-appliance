@php
    // Custom Filament StatsOverviewWidget page. This component is a copy of the one included in Filament but uses
    // apexcharts.js instead. It opens a modal using the summary-table-modal component upon a click event.
    // Depending on the component, summary-table-modal will render a summary-table or a view-host-info component on the
    // This component is called from the SummaryBadge component

    use Filament\Support\Enums\IconPosition;
    use Filament\Widgets\View\Components\StatsOverviewWidgetComponent\StatComponent\DescriptionComponent;
    use Filament\Widgets\View\Components\StatsOverviewWidgetComponent\StatComponent\StatsOverviewWidgetStatChartComponent;
    use Illuminate\View\ComponentAttributeBag;
    use Filament\Support\Enums\IconSize;

    $columns = $this->getColumns();
    $pollingInterval = $this->getPollingInterval();

    $heading = $this->getHeading();
    $description = $this->getDescription();
    $hasHeading = filled($heading);
    $hasDescription = filled($description);
@endphp

<x-filament-widgets::widget
    :attributes="
        (new \Illuminate\View\ComponentAttributeBag)
            ->merge([
                'wire:poll.' . $pollingInterval => $pollingInterval ? true : null,
            ], escape: false)
            ->class([
                'fi-wi-stats-overview',
                'cursor-pointer',
                'mt-4',
                'p-2',
            ])
    "
>

    <div class="fi-wi-widget grid gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 ">

        @foreach ($this->getStats() as $stat)
            @php
                $chartColor              = $stat->getChartColor() ?? 'gray';
                $descriptionColor        = $stat->getDescriptionColor() ?? 'gray';
                $descriptionIcon         = $stat->getDescriptionIcon();
                $descriptionIconPosition = $this->getDescriptionIconPosition();
                $url                     = $stat->getUrl();
                $tag = $url ? 'a' : 'div';

                $conf = base64_encode(json_encode($stat->getChart()));
                $type = $stat->getLabel();
                $chart = $stat->getChart();
                $mark = "";
                isset($this->summaryData->{$type}->badgeData->mark) && $mark = $this->summaryData->{$type}->badgeData->mark;
            @endphp

            <{!! $tag !!}
                @if ($url)
                    {{ \Filament\Support\generate_href_html($url, $shouldOpenUrlInNewTab()) }}
                @endif
                {{ (new \Illuminate\View\ComponentAttributeBag)
                    ->merge([
                        'wire:click' => "\$dispatch('open-summary-modal', {type:'{$type}', vid:'{$this->vid}', did:'{$this->did}', cid:'{$this->cid}'})",
                    ], escape: false)
                    ->class([
                        'fi-wi-stats-overview-stat',
                    ])
                }}
            >
                <div class="fi-wi-stats-overview-stat-content">
                    @if ($description = $stat->getDescription())

                        <x-filament::button
                            label="{{ $description }}"
                            icon="{{ $descriptionIcon }}"
                            color="{{ $descriptionColor }}"
                            size="xl"
                            outlined
                            tooltip="See {{ $type }} details"
                        >
                            {{ $description }}
                        </x-filament::button>


                    @endif

                    <div class="fi-wi-stats-overview-stat-value mt-4 text-2xl h-16">
                        {{ substr($stat->getValue(), 0, 60) }}
                    </div>

                </div>

                @if(isset($this->summaryData->{$type}->badgeData->mark))
                    <header class="flex justify-center items-center text-center text-2xl leading-none tracking-tight p-4 h-20 mt-16 mb-0 rounded-lg bg-{{$descriptionColor}}-600" >
                        {{ $mark }}
                    </header>
                @endif

                @if (isset($chart))
                    <div x-data="{ summaryBadgeChart() {} }">
                        <div x-data="window.sosViewer.summaryBadgeChart('{{ $conf }}', '{{ $type }}')"
                            {{ (new \Illuminate\View\ComponentAttributeBag)->color(StatsOverviewWidgetStatChartComponent::class, $chartColor)->class(['fi-wi-stats-overview-stat-chart','relative','mt-4']) }}
                        >
                            <div id="{{ $type }}_canvas"></div>

                            <span
                                x-ref="backgroundColorElement"
                                class="fi-wi-stats-overview-stat-chart-bg-color"
                            ></span>

                            <span
                                x-ref="borderColorElement"
                                class="fi-wi-stats-overview-stat-chart-border-color"
                            ></span>
                        </div>
                    </div>
                @endif

            </{!! $tag !!}>

        @endforeach
    </div>

    <div class="hidden
        bg-info-800
        bg-info-700
        bg-info-600
        bg-info-500
        bg-info-400
        bg-info-300
        bg-info-200
        bg-info-100

        text-info-800
        text-info-700
        text-info-600
        text-info-500
        text-info-400
        text-info-300
        text-info-200
        text-info-100

        bg-primary-800
        bg-primary-700
        bg-primary-600
        bg-primary-500
        bg-primary-400
        bg-primary-300
        bg-primary-200
        bg-primary-100

        text-primary-800
        text-primary-700
        text-primary-600
        text-primary-500
        text-primary-400
        text-primary-300
        text-primary-200
        text-primary-100

        bg-danger-800
        bg-danger-700
        bg-danger-600
        bg-danger-500
        bg-danger-400
        bg-danger-300
        bg-danger-200
        bg-danger-100

        text-danger-800
        text-danger-700
        text-danger-600
        text-danger-500
        text-danger-400
        text-danger-300
        text-danger-200
        text-danger-100

        bg-warning-800
        bg-warning-700
        bg-warning-600
        bg-warning-500
        bg-warning-400
        bg-warning-300
        bg-warning-200
        bg-warning-100

        text-warning-800
        text-warning-700
        text-warning-600
        text-warning-500
        text-warning-400
        text-warning-300
        text-warning-200
        text-warning-100

        bg-gray-800
        bg-gray-700
        bg-gray-600
        bg-gray-500
        bg-gray-400
        bg-gray-300
        bg-gray-200
        bg-gray-100

        text-gray-800
        text-gray-700
        text-gray-600
        text-gray-500
        text-gray-400
        text-gray-300
        text-gray-200
        text-gray-100
    "></div>

</x-filament-widgets::widget>
