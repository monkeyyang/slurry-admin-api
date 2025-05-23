<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('auto_execution_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->integer('execution_interval')->default(30); // minutes
            $table->integer('max_concurrent_plans')->default(5);
            $table->string('log_level')->default('info');
            $table->timestamp('last_execution_time')->nullable();
            $table->timestamp('next_execution_time')->nullable();
            $table->timestamps();
        });

        // Insert default settings
        DB::table('auto_execution_settings')->insert([
            'enabled' => true,
            'execution_interval' => 30,
            'max_concurrent_plans' => 5,
            'log_level' => 'info',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auto_execution_settings');
    }
}; 