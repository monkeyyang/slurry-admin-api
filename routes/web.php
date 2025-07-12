<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WechatMonitorController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// 微信消息监控面板（Web界面）
Route::prefix('wechat/monitor')->group(function () {
    Route::get('/', [WechatMonitorController::class, 'index'])->name('wechat.monitor.index');
});

// 微信消息监控API（保持在API路由中）
Route::prefix('api/wechat/monitor')->group(function () {
    Route::get('/stats', [WechatMonitorController::class, 'getStats']);
    Route::get('/messages', [WechatMonitorController::class, 'getMessages']);
    Route::get('/rooms', [WechatMonitorController::class, 'getRooms']);
    Route::get('/config', [WechatMonitorController::class, 'getConfig']);
    Route::post('/retry', [WechatMonitorController::class, 'retryMessage']);
    Route::post('/batch-retry', [WechatMonitorController::class, 'batchRetry']);
    Route::post('/test-message', [WechatMonitorController::class, 'sendTestMessage']);
}); 