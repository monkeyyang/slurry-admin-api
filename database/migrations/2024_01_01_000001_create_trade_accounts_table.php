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
        Schema::create('trade_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account')->unique()->comment('账号');
            $table->text('password')->nullable()->comment('加密后的密码');
            $table->string('api_url')->nullable()->comment('API验证URL');
            $table->string('country', 10)->comment('国家代码');
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active')->comment('状态');
            $table->string('imported_by')->nullable()->comment('导入者用户名');
            $table->unsignedBigInteger('imported_by_user_id')->nullable()->comment('导入者用户ID');
            $table->string('imported_by_nickname')->nullable()->comment('导入者昵称');
            $table->timestamp('imported_at')->nullable()->comment('导入时间');
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamps();

            $table->index(['country', 'status']);
            $table->index(['imported_by_user_id']);
            $table->index(['imported_at']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_accounts');
    }
}; 