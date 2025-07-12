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
        Schema::create('wechat_message_logs', function (Blueprint $table) {
            $table->id();
            $table->string('room_id', 50)->index()->comment('微信群ID');
            $table->string('message_type', 20)->default('text')->comment('消息类型');
            $table->text('content')->comment('消息内容');
            $table->string('from_source', 50)->nullable()->comment('来源');
            $table->tinyInteger('status')->default(0)->comment('状态: 0-待发送, 1-发送成功, 2-发送失败');
            $table->integer('retry_count')->default(0)->comment('重试次数');
            $table->integer('max_retry')->default(3)->comment('最大重试次数');
            $table->json('api_response')->nullable()->comment('API响应数据');
            $table->text('error_message')->nullable()->comment('错误信息');
            $table->timestamp('sent_at')->nullable()->comment('发送时间');
            $table->timestamps();
            
            $table->index(['status', 'created_at']);
            $table->index(['room_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wechat_message_logs');
    }
}; 