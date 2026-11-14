<?php

namespace App\Livewire;

use Carbon\Carbon;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Wave\Subscription;

class PlanBadge extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected array|string|int $columnSpan = 'full';

    protected ?string $heading = null;

    protected function getStats(): array
    {
        $user = auth()->user();
        $roleName = $user->role?->name ?? 'Unknown';

        // ── Free trial ──────────────────────────────────────────────────────
        if ($user->onTrial()) {
            $daysLeft = $user->daysLeftOnTrial();
            $trialEnds = Carbon::parse($user->trial_ends_at)->format('Y-m-d');
            $expired = Carbon::parse($user->trial_ends_at)->isPast();
            $color = $expired ? 'danger' : ($daysLeft <= 3 ? 'danger' : ($daysLeft <= 7 ? 'warning' : 'success'));

            $endsKey = $expired ? 'plan.badge_trial_ended' : 'plan.badge_trial_ends';
            $statusKey = $expired ? 'plan.badge_trial_inactive' : 'plan.badge_trial_active';

            return [
                Stat::make(__('plan.badge_plan'), $roleName)
                    ->description(__($endsKey, ['date' => $trialEnds]))
                    ->descriptionIcon('phosphor-clock-countdown-duotone', IconPosition::Before)
                    ->descriptionColor($color)
                    ->color($color),

                Stat::make(__('plan.badge_days_left'), $daysLeft)
                    ->description(__($statusKey))
                    ->descriptionIcon('phosphor-hourglass-duotone', IconPosition::Before)
                    ->descriptionColor($color)
                    ->color($color),
            ];
        }

        // ── Group member (not the owner) ─────────────────────────────────
        if ($user->group_id) {
            $group = $user->group;
            $isOwner = $group && (int) $group->owner_id === (int) $user->id;

            if (! $isOwner) {
                return [
                    Stat::make(__('plan.badge_plan'), $roleName)
                        ->description(__('plan.badge_member_role'))
                        ->descriptionIcon('phosphor-users-duotone', IconPosition::Before)
                        ->descriptionColor('primary')
                        ->color('primary'),
                ];
            }
        }

        // ── Paid subscriber (solo or group owner) ────────────────────────
        $sub = Subscription::where('billable_id', $user->id)
            ->where('billable_type', 'user')
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($sub) {
            $nextBilling = $this->nextBillingDate($sub);
            $daysUntil = Carbon::now()->startOfDay()->diffInDays($nextBilling->startOfDay(), false);
            $color = $daysUntil <= 3 ? 'danger' : ($daysUntil <= 7 ? 'warning' : 'primary');
            $cycle = $sub->cycle === 'year' ? __('plan.badge_yearly') : __('plan.badge_monthly');

            return [
                Stat::make(__('plan.badge_plan'), $roleName)
                    ->description($cycle)
                    ->descriptionIcon('phosphor-credit-card-duotone', IconPosition::Before)
                    ->descriptionColor('primary')
                    ->color('primary'),

                Stat::make(__('plan.badge_next_billing'), $nextBilling->format('Y-m-d'))
                    ->description(__('plan.badge_days_until', ['days' => max(0, (int) $daysUntil)]))
                    ->descriptionIcon('phosphor-calendar-check-duotone', IconPosition::Before)
                    ->descriptionColor($color)
                    ->color($color),
            ];
        }

        // ── Admin or no subscription info ────────────────────────────────
        return [
            Stat::make(__('plan.badge_plan'), $roleName)
                ->description(__('plan.badge_no_subscription'))
                ->descriptionIcon('phosphor-shield-star-duotone', IconPosition::Before)
                ->descriptionColor('primary')
                ->color('primary'),
        ];
    }

    private function nextBillingDate(Subscription $sub): Carbon
    {
        $start = Carbon::parse($sub->created_at);
        $now = Carbon::now();

        if ($sub->cycle === 'year') {
            $next = $start->copy();
            while ($next->lte($now)) {
                $next->addYear();
            }
        } else {
            // Default: monthly
            $next = $start->copy();
            while ($next->lte($now)) {
                $next->addMonth();
            }
        }

        return $next;
    }
}
