<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paddle_subscriptions')) {
            return;
        }

        Schema::create('paddle_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('subscription_id', 255)->unique();
            $table->string('plan_id', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->nullable();
            $table->text('update_url')->nullable();
            $table->text('cancel_url')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('next_payment_at')->nullable();
            $table->timestamp('delete_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paddle_subscriptions');
    }
};
