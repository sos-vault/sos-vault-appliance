<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $plans = DB::table('plans')->where('type', 'service')->get();

        foreach ($plans as $plan) {
            if (! json_validate($plan->features)) {
                continue;
            }

            $features = json_decode($plan->features, true);
            $sortOrder = 0;

            foreach ($features as $name => $attr) {
                if ($name === 'Price') {
                    continue;
                }

                DB::table('plan_features')->insert([
                    'plan_id' => $plan->id,
                    'name' => $name,
                    'type' => $attr['type'] ?? 'bool',
                    'enabled' => (bool) ($attr['enable'] ?? false),
                    'amount' => isset($attr['amount']) && $attr['amount'] > 0 ? $attr['amount'] : null,
                    'units' => $attr['units'] ?? null,
                    'description' => $attr['description'] ?? '',
                    'status' => $attr['status'] ?? 'ready',
                    'sort_order' => $sortOrder++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('plan_features')->delete();
    }
};
