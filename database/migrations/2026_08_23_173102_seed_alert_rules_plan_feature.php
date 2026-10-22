<?php

use App\Models\PlanFeature;
use Illuminate\Database\Migrations\Migration;
use Wave\Plan;

/**
 * Grants the "Alert Rules" feature to Team/Enterprise plans, gating the whole
 * Alerts tool on SaaS (checkAccess($user, 'Alert Rules')). Deliberately not
 * seeded for any other plan or for the appliance role — the appliance branch
 * bypasses this check entirely (the tool is open to all appliance users; only
 * the Slack contact point requires a license, enforced separately).
 */
return new class extends Migration
{
    private const FEATURE = 'Alert Rules';

    private const PLANS = ['Team', 'Enterprise'];

    public function up(): void
    {
        foreach (self::PLANS as $name) {
            $plan = Plan::where('type', 'service')->whereEnglishName($name)->first();
            if (! $plan) {
                continue;
            }

            $exists = $plan->planFeatures()
                ->whereRaw("json_extract(name, '$.en') = ?", [self::FEATURE])
                ->exists();

            if ($exists) {
                continue;
            }

            PlanFeature::create([
                'plan_id' => $plan->id,
                'name' => self::FEATURE,
                'type' => 'bool',
                'enabled' => true,
                'description' => 'Create alert rules evaluated automatically on every new sosreport upload, with in-app, email, event-log, and Slack delivery.',
                'status' => 'ready',
                'sort_order' => 99,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::PLANS as $name) {
            $plan = Plan::where('type', 'service')->whereEnglishName($name)->first();
            if (! $plan) {
                continue;
            }

            $plan->planFeatures()
                ->whereRaw("json_extract(name, '$.en') = ?", [self::FEATURE])
                ->delete();
        }
    }
};
