<?php

namespace App\Filament\Widgets;

use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Sprint 5 Step D — appliance dashboard: license + seat-usage overview.
 *
 * Hidden on the SaaS build via canView(). Reads from LocalLicense::current()
 * (Step B), so when no license is installed the widget renders a neutral
 * "No license installed" placeholder rather than disappearing — operators
 * need a visual cue that the appliance is unlicensed.
 */
class ApplianceLicenseWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return isAppliance();
    }

    protected function getStats(): array
    {
        $license = LocalLicense::current();
        $userCount = User::query()->count();
        $teamCount = Group::query()->count();

        if (! $license) {
            // Open-core baseline: highlight the upgrade path and the locked
            // features so the operator knows the appliance is functional but
            // running in single-admin mode.
            return [
                Stat::make(
                    __('licensing.dashboard.unlicensed_title'),
                    __('licensing.dashboard.unlicensed_value')
                )
                    ->description(__('licensing.dashboard.unlicensed_callout'))
                    ->descriptionIcon('phosphor-key-duotone')
                    ->color('warning')
                    ->url('/admin/manage-license')
                    ->icon('phosphor-info-duotone'),

                Stat::make(__('appliance.widget_license.seats_title'), (string) $userCount)
                    ->description(__('appliance.widget_license.seats_users_in_system'))
                    ->color('gray')
                    ->icon('phosphor-users-duotone'),

                Stat::make(__('appliance.widget_license.teams_title'), (string) $teamCount)
                    ->description(__('appliance.widget_license.teams_groups_configured'))
                    ->color('gray')
                    ->icon('phosphor-users-three-duotone'),

                Stat::make(__('appliance.widget_license.expiry_title'), __('appliance.widget_license.expiry_dash'))
                    ->description(__('appliance.widget_license.expiry_no_license'))
                    ->color('gray')
                    ->icon('phosphor-calendar-x-duotone'),
            ];
        }

        $daysLeft = (int) now()->diffInDays($license->expires_at, false);
        $expiryColor = match (true) {
            $daysLeft <= 7 => 'danger',
            $daysLeft <= 30 => 'warning',
            default => 'success',
        };
        $expiryLabel = $daysLeft > 0
            ? trans_choice('appliance.widget_license.expiry_days_left', $daysLeft, ['count' => $daysLeft])
            : __('appliance.widget_license.expiry_expired');

        // One seat is always reserved for the admin operator and is not
        // billed (see LicenseCheckoutService: "the always-included admin").
        // Present seats in user-facing terms — exclude the admin from both
        // the count and the total — so a 10-user license with only the admin
        // present reads "0 / 10", not "1 / 11".
        $reservedAdminSeats = 1;
        $usedSeats = max(0, $userCount - $reservedAdminSeats);
        $totalSeats = max(0, $license->seats - $reservedAdminSeats);

        $seatColor = match (true) {
            $usedSeats >= $totalSeats => 'danger',
            $usedSeats >= max(1, $totalSeats - 1) => 'warning',
            default => 'success',
        };

        $statusLabel = match (strtoupper($license->status ?? 'UNKNOWN')) {
            'ACTIVE' => __('licensing.status.active'),
            'EXPIRED' => __('licensing.status.expired'),
            'REVOKED' => __('licensing.status.revoked'),
            default => strtoupper($license->status ?? 'UNKNOWN'),
        };

        return [
            Stat::make(__('appliance.widget_license.title'), $statusLabel)
                ->description(__('appliance.widget_license.uuid_desc', ['uuid' => $license->uuid]))
                ->color($license->status === 'ACTIVE' ? 'success' : 'danger')
                ->icon('phosphor-certificate-duotone'),

            Stat::make(__('appliance.widget_license.seats_title'), "{$usedSeats} / {$totalSeats}")
                ->description(implode(', ', $license->features ?? []) ?: __('appliance.widget_license.no_features'))
                ->color($seatColor)
                ->icon('phosphor-users-duotone'),

            Stat::make(__('appliance.widget_license.teams_title'), (string) $teamCount)
                ->description(__('appliance.widget_license.teams_configured_groups'))
                ->color('info')
                ->icon('phosphor-users-three-duotone'),

            Stat::make(__('appliance.widget_license.expiry_title'), $expiryLabel)
                ->description($license->expires_at?->toFormattedDateString() ?? __('appliance.widget_license.expiry_dash'))
                ->color($expiryColor)
                ->icon('phosphor-calendar-duotone'),
        ];
    }
}
