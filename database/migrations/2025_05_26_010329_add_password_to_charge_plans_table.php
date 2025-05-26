<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('charge_plans', function (Blueprint $table) {
            $table->string('password')->nullable()->after('account')->comment('账号密码');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charge_plans', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
