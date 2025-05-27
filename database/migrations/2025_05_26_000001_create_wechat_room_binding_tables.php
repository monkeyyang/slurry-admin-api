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
            $table->id()->comment('主键ID');
            $table->boolean('enabled')->default(false)->comment('是否启用微信群组绑定功能');
            $table->boolean('auto_assign')->default(false)->comment('是否启用自动分配计划到群组');
            $table->string('default_room_id')->nullable()->comment('默认分配的微信群组ID');
            $table->integer('max_plans_per_room')->default(10)->comment('每个微信群组最多可绑定的计划数量');
            $table->timestamps()->comment('创建和更新时间');
        });

        // 充值计划微信群组绑定表
        Schema::create('charge_plan_wechat_room_bindings', function (Blueprint $table) {
            $table->id()->comment('主键ID');
            $table->unsignedBigInteger('plan_id')->comment('关联的充值计划ID');
            $table->string('room_id')->comment('绑定的微信群组ID');
            $table->timestamp('bound_at')->useCurrent()->comment('计划绑定到群组的时间');
            $table->timestamps()->comment('创建和更新时间');
            
            $table->foreign('plan_id')->references('id')->on('charge_plans')->onDelete('cascade');
            $table->index(['plan_id', 'room_id'])->comment('计划ID和群组ID的联合索引');
            $table->unique('plan_id')->comment('确保一个计划只能绑定到一个群组');
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