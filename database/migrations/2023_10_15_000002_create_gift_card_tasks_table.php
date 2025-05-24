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
        Schema::create('gift_card_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_id')->unique()->comment('外部API任务ID');
            $table->string('type')->comment('任务类型: login, query, redeem');
            $table->string('status')->default('pending')->comment('任务状态: pending, processing, completed, failed');
            $table->json('request_data')->nullable()->comment('请求数据');
            $table->json('result_data')->nullable()->comment('结果数据');
            $table->text('error_message')->nullable()->comment('错误信息');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');
            $table->timestamps();
            
            // 索引
            $table->index('type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gift_card_tasks');
    }
}; 