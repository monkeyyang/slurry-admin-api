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
        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('country');
            $table->decimal('total_target_amount', 10, 2)->nullable();
            $table->decimal('current_amount', 10, 2)->default(0);
            $table->enum('status', ['active', 'paused', 'completed'])->default('active');
            $table->integer('account_count')->default(0);
            $table->boolean('auto_switch')->default(false);
            $table->decimal('switch_threshold', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_groups');
    }
}; 