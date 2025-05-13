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
        Schema::create('card_query_rules', function (Blueprint $table) {
            $table->id();
            $table->integer('first_interval')->default(25)->comment('首次查询间隔（分钟）');
            $table->integer('second_interval')->default(60)->comment('第二次查询额外间隔（分钟）');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamps();
        });
        
        // 插入默认规则
        DB::table('card_query_rules')->insert([
            'first_interval' => 25,
            'second_interval' => 60,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_query_rules');
    }
}; 