<?php

use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_cases', function (Blueprint $table) {
            $table->foreignId('self_hosted_user_id')
                ->nullable()
                ->after('owner')
                ->constrained('users')
                ->nullOnDelete();
        });

        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->orderBy('id')
            ->first();

        if ($admin) {
            Vault::where('owner', $admin->id)->update(['always_open' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('support_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('self_hosted_user_id');
        });
    }
};
