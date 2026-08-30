<?php

// SummaryBadge page. This componen ititerates on components defined in summaryData passed
// from sosTool/[vid]/[did]/Summary/[caseid] component. It uses filament.widgets.stats-overview-widget blade
// to display a custom Filament StatsOverviewWidget (resources/views/filament/widgets/stats-overview-widget.blade.php)
// Each StatsOverviewWidget opens a modal using the summary-table-modal blade upon a click event.
// Depending on the component, summary-table-modal will render a summary-table or a view-host-info component on the

namespace App\Livewire;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SummaryBadge extends StatsOverviewWidget
{
    // sort order
    protected static ?int $sort = 1;

    public $summaryData;

    public $vid;

    public $did;

    public $cid;

    protected ?string $pollingInterval = null;

    // home made view
    protected string $view = 'filament.widgets.stats-overview-widget';

    protected function getDescriptionIconPosition(): string
    {
        return 'before';
    }

    protected function getStats(): array
    {
        $widgets = [
            'host',
            'cpu',
            'memory',
            'disk',
            'procs',
            'conn',
            'systemd',
            'errors',
            'tcpip',
            'files',
            'firewall',
            'inventory',
            'limits',
            'packages',
            'kernel',
            'netstats',
        ];

        $board = [];
        foreach ($widgets as $widget) {
            if (isset($this->summaryData->{$widget}) && isset($this->summaryData->{$widget}->badgeData)) {

                $subtitle = $this->summaryData->{$widget}->badgeData->subTitle;
                if ($widget == 'host') {
                    $subtitle = substr($subtitle, 0, 16);
                }

                $board[] = Stat::make($widget, $subtitle)
                    ->description($this->summaryData->{$widget}->badgeData->mainTitle)
                    ->descriptionIcon($this->summaryData->{$widget}->badgeData->icon)
                    ->descriptionColor($this->summaryData->{$widget}->badgeData->color)
                    ->color($this->summaryData->{$widget}->badgeData->color)
                    ->chartColor($this->summaryData->{$widget}->badgeData->color)
                    ->chart($this->summaryData->{$widget}->badgeData->chart);
            }
        }

        return $board;
    }
}
