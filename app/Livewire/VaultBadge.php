<?php

namespace App\Livewire;

use Carbon\Carbon;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

class VaultBadge extends StatsOverviewWidget
{
    public $vaultData;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected array|string|int $columnSpan = 'full';

    protected ?string $heading = null;

    public function getHeading(): string
    {
        return __('vault.badge_vault_heading');
    }

    /**
     * Re-pull vault usage when a sibling component (e.g. the vault page's
     * Repack / Delete actions) dispatches `refreshComponents`. The widget is
     * its own Livewire instance so the parent's $wire.$refresh() does NOT
     * automatically propagate new properties — without this listener the
     * stats would stay stale until a full page reload.
     */
    #[On('refreshComponents')]
    public function reloadVaultData(): void
    {
        $fresh = buildVaultBadgeData();
        if ($fresh !== null) {
            $this->vaultData = $fresh;
        }
    }

    protected function getStats(): array
    {
        // log::info(var_export($this->vaultData, true));
        if (! $this->vaultData) {
            return [];
        }

        $chart = null;
        $board = [];

        // vault state and shared status
        $icon = $this->vaultData['shared'] ? 'phosphor-lock-key-open-duotone' : 'phosphor-lock-key-duotone';
        $color = 'primary';
        $board[] = Stat::make(__('vault.badge_current_state'), $this->vaultData['state'])
            ->description(fn () => $this->vaultData['shared'] ? __('vault.badge_shared') : __('vault.badge_not_shared'))
            ->descriptionIcon($icon, IconPosition::Before)
            ->descriptionColor($color)
            ->color($color)
            ->chartColor($color)
            ->chart($chart);

        // vault size and usage
        $pct = (int) $this->vaultData['pusage'];
        $icon = 'phosphor-database-duotone';
        $color = match (true) {
            $pct >= 85 => 'danger',
            $pct >= 80 => 'warning',
            default => 'success',
        };
        $board[] = Stat::make(__('vault.badge_vault_size'), $this->vaultData['size'].'B')
            ->description(__('vault.badge_usage_full', ['usage' => $this->vaultData['pusage']]))
            ->descriptionIcon($icon, IconPosition::Before)
            ->descriptionColor($color)
            ->color($color)
            ->chartColor($color)
            ->chart($chart)
            ->view('filament.vault-size-stat', ['pct' => $pct]);

        // vault access times
        $icon = 'phosphor-door-open-duotone';
        $color = 'primary';
        $board[] = Stat::make(__('vault.badge_last_access'), Carbon::parse($this->vaultData['last_open'])->format('Y-m-d H:i'))
            ->description(__('vault.badge_last_close', ['time' => Carbon::parse($this->vaultData['last_close'])->format('Y-m-d H:i')]))
            ->descriptionIcon($icon, IconPosition::Before)
            ->descriptionColor($color)
            ->color($color)
            ->chartColor($color)
            ->chart($chart);

        // packed files
        $icon = 'phosphor-file-lock-duotone';
        $color = 'primary';
        $board[] = Stat::make(__('vault.badge_sos_reports'), "{$this->vaultData['pfiles']} files")
            ->description(__('vault.badge_packed_files'))
            ->descriptionIcon($icon, IconPosition::Before)
            ->descriptionColor($color)
            ->color($color)
            ->chartColor($color)
            ->chart($chart);

        // extracted dirs
        $icon = 'phosphor-folders-duotone';
        $color = 'primary';
        $board[] = Stat::make(__('vault.badge_vault_usage'), "{$this->vaultData['udirs']} folders")
            ->description(__('vault.badge_unpacked_folders'))
            ->descriptionIcon($icon, IconPosition::Before)
            ->descriptionColor($color)
            ->color($color)
            ->chartColor($color)
            ->chart($chart);

        // support cases dirs
        $icon = 'phosphor-ticket-duotone';
        $color = 'primary';
        $board[] = Stat::make(__('vault.badge_support_cases'), "{$this->vaultData['cases']} cases")
            ->description(__('vault.badge_open_cases'))
            ->descriptionIcon($icon, IconPosition::Before)
            ->descriptionColor($color)
            ->color($color)
            ->chartColor($color)
            ->chart($chart);

        return $board;
    }
}
