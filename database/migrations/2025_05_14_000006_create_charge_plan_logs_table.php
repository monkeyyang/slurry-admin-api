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
        Schema::create('charge_plan_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('charge_plans')->onDelete('cascade');
            $table->foreignId('item_id')->nullable()->constrained('charge_plan_items')->onDelete('set null');
            $table->integer('day')->nullable();
            $table->time('time');
            $table->string('action');
            $table->enum('status', ['success', 'failed']);
            $table->text('details')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_plan_logs');
    }
}; 