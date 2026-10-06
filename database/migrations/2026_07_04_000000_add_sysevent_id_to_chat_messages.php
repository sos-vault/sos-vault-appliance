<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chat_messages', 'sysevent_id')) {
            return;
        }

        Schema::table('chat_messages', function (Blueprint $table) {
            // Links an assistant reply to its BOT_* Sysevent so a 👍/👎 rating can
            // update that event's quality. Nullable: user messages and pre-existing
            // rows have none. No FK constraint — sysevents is a lightweight append log.
            $table->unsignedBigInteger('sysevent_id')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('chat_messages', 'sysevent_id')) {
            return;
        }

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('sysevent_id');
        });
    }
};
