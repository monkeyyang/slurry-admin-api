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
        // 微信群组绑定设置表
        Schema::create('wechat_room_binding_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false)->comment('是否启用微信群组绑定');
            $table->boolean('auto_assign')->default(false)->comment('是否自动分配');
            $table->string('default_room_id')->nullable()->comment('默认群组ID');
            $table->integer('max_plans_per_room')->default(10)->comment('每个群组最大计划数');
            $table->timestamps();
        });

        // 充值计划微信群组绑定表
        Schema::create('charge_plan_wechat_room_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id')->comment('充值计划ID');
            $table->string('room_id')->comment('微信群组ID');
            $table->timestamp('bound_at')->useCurrent()->comment('绑定时间');
            $table->timestamps();
            
            $table->foreign('plan_id')->references('id')->on('charge_plans')->onDelete('cascade');
            $table->index(['plan_id', 'room_id']);
            $table->unique('plan_id'); // 一个计划只能绑定一个群组
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_plan_wechat_room_bindings');
        Schema::dropIfExists('wechat_room_binding_settings');
    }
}; 