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
        Schema::create('card_query_records', function (Blueprint $table) {
            $table->id();
            $table->string('card_code')->comment('卡密编码');
            $table->tinyInteger('query_count')->default(0)->comment('查询次数');
            $table->timestamp('first_query_at')->nullable()->comment('首次查询时间');
            $table->timestamp('second_query_at')->nullable()->comment('第二次查询时间');
            $table->timestamp('next_query_at')->nullable()->comment('下次查询时间');
            $table->boolean('is_valid')->default(false)->comment('是否有效卡密');
            $table->text('response_data')->nullable()->comment('API返回数据');
            $table->boolean('is_completed')->default(false)->comment('是否完成查询（不再查询）');
            $table->timestamps();
            
            $table->index('card_code');
            $table->index('next_query_at');
            $table->index('is_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_query_records');
    }
}; 