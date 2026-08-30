<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SQLite stores everything as text, so no schema type change is needed.
// Spatie HasTranslations reads/writes JSON strings regardless of declared column type.
// For MySQL/PostgreSQL, add column type changes separately after migrating off SQLite.

return new class extends Migration
{
    public function up(): void
    {
        // Wrap existing English strings in {"en": "..."} JSON for plans
        DB::table('plans')->get()->each(function ($plan) {
            // Skip rows already migrated (valid JSON object)
            if (json_validate($plan->name) && is_array(json_decode($plan->name, true))) {
                return;
            }

            DB::table('plans')->where('id', $plan->id)->update([
                'name' => json_encode(['en' => $plan->name], JSON_UNESCAPED_UNICODE),
                'description' => json_encode(['en' => $plan->description ?? ''], JSON_UNESCAPED_UNICODE),
            ]);
        });

        // Wrap existing English strings in {"en": "..."} JSON for plan_features
        DB::table('plan_features')->get()->each(function ($feature) {
            if (json_validate($feature->name) && is_array(json_decode($feature->name, true))) {
                return;
            }

            DB::table('plan_features')->where('id', $feature->id)->update([
                'name' => json_encode(['en' => $feature->name], JSON_UNESCAPED_UNICODE),
                'description' => json_encode(['en' => $feature->description ?? ''], JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function down(): void
    {
        // Unwrap JSON back to English string for plans
        DB::table('plans')->get()->each(function ($plan) {
            $name = json_decode($plan->name, true);
            $desc = json_decode($plan->description, true);

            if (! is_array($name)) {
                return;
            }

            DB::table('plans')->where('id', $plan->id)->update([
                'name' => $name['en'] ?? '',
                'description' => $desc['en'] ?? '',
            ]);
        });

        // Unwrap JSON back to English string for plan_features
        DB::table('plan_features')->get()->each(function ($feature) {
            $name = json_decode($feature->name, true);
            $desc = json_decode($feature->description, true);

            if (! is_array($name)) {
                return;
            }

            DB::table('plan_features')->where('id', $feature->id)->update([
                'name' => $name['en'] ?? '',
                'description' => $desc['en'] ?? '',
            ]);
        });
    }
};
