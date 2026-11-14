<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('itsmproviders')) {
            return;
        }

        Schema::create('itsmproviders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vid');
            $table->unsignedBigInteger('uid');
            $table->integer('gid');
            $table->string('provider')->unique();
            $table->string('url');
            $table->string('tenant')->nullable();
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('user')->nullable();
            $table->string('password', 1024)->nullable();
            $table->string('api_key', 1024)->nullable();
            $table->string('api_token', 1024)->nullable();
            $table->string('customer_field')->nullable();
            $table->timestamp('last_connection')->nullable();
            $table->timestamp('last_download')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itsmproviders');
    }
};
