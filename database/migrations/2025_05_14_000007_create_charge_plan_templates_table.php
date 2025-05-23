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
        Schema::create('charge_plan_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country');
            $table->decimal('total_amount', 10, 2);
            $table->integer('days');
            $table->decimal('multiple_base', 10, 2);
            $table->decimal('float_amount', 10, 2);
            $table->integer('interval_hours');
            $table->json('items')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_plan_templates');
    }
}; 