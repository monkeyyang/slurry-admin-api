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
        Schema::create('charge_plans', function (Blueprint $table) {
            $table->id();
            $table->string('account');
            $table->string('country');
            $table->decimal('total_amount', 10, 2);
            $table->integer('days');
            $table->decimal('multiple_base', 10, 2);
            $table->decimal('float_amount', 10, 2);
            $table->integer('interval_hours');
            $table->dateTime('start_time');
            $table->enum('status', ['draft', 'processing', 'paused', 'completed', 'cancelled'])->default('draft');
            $table->integer('current_day')->nullable();
            $table->decimal('progress', 5, 2)->nullable();
            $table->decimal('charged_amount', 10, 2)->nullable()->default(0);
            $table->foreignId('group_id')->nullable()->constrained('account_groups')->onDelete('set null');
            $table->integer('priority')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_plans');
    }
}; 