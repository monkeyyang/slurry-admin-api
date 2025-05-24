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
        Schema::create('account_balance_limits', function (Blueprint $table) {
            $table->id();
            $table->string('account')->unique()->comment('账号');
            $table->decimal('balance_limit', 10, 2)->comment('余额上限');
            $table->decimal('current_balance', 10, 2)->default(0)->comment('当前余额');
            $table->string('status')->default('active')->comment('状态: active, inactive');
            $table->timestamp('last_redemption_at')->nullable()->comment('上次兑换时间');
            $table->timestamp('last_checked_at')->nullable()->comment('上次检查余额时间');
            $table->timestamps();
            
            // 索引
            $table->index('status');
            $table->index('current_balance');
            $table->index('balance_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_balance_limits');
    }
}; 