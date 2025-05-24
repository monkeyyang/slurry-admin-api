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
        Schema::create('gift_card_exchange_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->nullable()->constrained('charge_plans')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('charge_plan_items')->nullOnDelete();
            $table->string('account')->nullable()->comment('关联账号');
            $table->string('card_number')->comment('礼品卡号');
            $table->integer('card_type')->comment('卡类型，例如 /1, /2');
            $table->string('country_code')->comment('国家代码');
            $table->decimal('original_balance', 10, 2)->comment('原始余额');
            $table->string('original_currency')->comment('原始货币');
            $table->decimal('exchange_rate', 10, 4)->comment('兑换汇率');
            $table->decimal('converted_amount', 10, 2)->comment('转换金额');
            $table->string('target_currency')->comment('目标货币');
            $table->string('transaction_id')->comment('交易ID');
            $table->string('status')->default('success')->comment('状态');
            $table->text('details')->nullable()->comment('详细信息');
            $table->timestamp('exchange_time')->comment('兑换时间');
            $table->string('task_id')->nullable()->comment('相关任务ID');
            $table->timestamps();
            
            // 索引
            $table->index('card_number');
            $table->index('country_code');
            $table->index('status');
            $table->index('exchange_time');
            $table->index('task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gift_card_exchange_records');
    }
}; 