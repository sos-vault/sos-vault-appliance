<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('users', function(Blueprint $table)
        {
	    $table->string('provider_id')->nullable();
	    $table->string('provider')->nullable();
	    $table->string('token')->nullable();
	    $table->string('refresh_token')->nullable();
        });
    }


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function(Blueprint $table)
        {
            $table->dropColumn('provider');
            $table->dropColumn('provider_id');
            $table->dropColumn('token');
            $table->dropColumn('refresh_token');
        });
    }

};
