<?php

use App\Http\Controllers\AdminMenuController;
use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\CountriesController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\InvitationCodeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\WarehouseForecastController;
use App\Http\Controllers\WarehouseGoodsController;
use App\Http\Controllers\AdminWarehouseController;
use App\Http\Controllers\WarehouseStockInController;
use App\Http\Controllers\WechatController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return "welcome";
});

Route::get('/getConfig', [ConfigController::class, 'getConfig'])->middleware('throttle:100,30');
Route::get('/captchaImage', [LoginController::class, 'captchaImage'])->middleware('throttle:100,30');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:100,30');
Route::post('/register', [RegisterController::class, 'register'])->middleware('throttle:100,30');

// // 微信机器人接口
// Route::group(['middleware' => 'auth'], function () {
//     Route::get('/logout', [LoginController::class, 'logout'])->middleware('throttle:100,30');
// });

Route::any('/api/wechat/webhook', [WechatController::class, 'index']);
Route::get('/api/wechat/test', [WechatController::class, 'test']);

Route::group(['middleware' => ['auth:api']], function() {
    Route::get('/admin/user/list', [AdminUserController::class, 'getAdminUserList']);
    Route::post('/admin/user/create', [AdminUserController::class, 'create']);
    Route::post('/admin/user/update', [AdminUserController::class, 'update']);
    Route::post('/admin/user/updateStatus', [AdminUserController::class, 'updateStatus']);
    Route::post('/admin/user/resetPassword', [AdminUserController::class, 'resetPassword']);
    Route::post('/admin/user/delete', [AdminUserController::class, 'delete']);

    Route::get('/admin/user/profile/info', [AdminUserController::class, 'profile']);
    Route::post('/admin/user/profile/password', [AdminUserController::class, 'changePassword']);

    Route::get('/admin/role/all', [AdminRoleController::class, 'getAllRole']);
    Route::get('/admin/role/list', [AdminRoleController::class, 'getRoleList']);
    Route::get('/admin/role/info', [AdminRoleController::class, 'getRoleInfo']);
    Route::post('/admin/role/create', [AdminRoleController::class, 'create']);
    Route::post('/admin/role/update', [AdminRoleController::class, 'update']);
    Route::post('/admin/role/updateStatus', [AdminRoleController::class, 'updateStatus']);
    Route::post('/admin/role/delete', [AdminRoleController::class, 'delete']);

    Route::get('/admin/menu/list', [AdminMenuController::class, 'getMenuList']);
    Route::get('/admin/menu/tree', [AdminMenuController::class, 'getMenuTree']);
    Route::get('/admin/menu/info', [AdminMenuController::class, 'getMenuInfo']);
    Route::post('/admin/menu/create', [AdminMenuController::class, 'create']);
    Route::post('/admin/menu/update', [AdminMenuController::class, 'update']);
    Route::post('/admin/menu/delete', [AdminMenuController::class, 'delete']);

    Route::get('/system/invite-code/list', [InvitationCodeController::class, 'index']);
    Route::post('/system/invite-code', [InvitationCodeController::class, 'store']);
    Route::post('/system/invite-code/generate', [InvitationCodeController::class, 'generate']);

    // 国家管理
    Route::get('/system/countries/list', [CountriesController::class, 'index']);
    Route::post('/system/countries/disable/{id}', [CountriesController::class, 'disable']);
    Route::post('/system/countries/enable/{id}', [CountriesController::class, 'enable']);
    // 货品管理路由
    Route::get('/warehouse/goods/list', [WarehouseGoodsController::class, 'index']);
    Route::get('/warehouse/goods/info/{id}', [WarehouseGoodsController::class, 'show']);
    Route::post('/warehouse/goods/create', [WarehouseGoodsController::class, 'store']);
    Route::post('/warehouse/goods/update/{id}', [WarehouseGoodsController::class, 'update']);
    Route::post('/warehouse/goods/delete/{id}', [WarehouseGoodsController::class, 'destroy']);
    Route::post('/warehouse/goods/alias/delete/{id}', [WarehouseGoodsController::class, 'destroyAlias']);
    Route::delete('/warehouse/goods/batch', [WarehouseGoodsController::class, 'batchDestroy']);

    // 仓库管理路由
    Route::get('/admin/warehouse/list', [AdminWarehouseController::class, 'index']);
    Route::get('/admin/warehouse/all', [AdminWarehouseController::class, 'all']);
    Route::get('/admin/warehouse/info/{id}', [AdminWarehouseController::class, 'show']);
    Route::post('/admin/warehouse/create', [AdminWarehouseController::class, 'store']);
    Route::post('/admin/warehouse/update/{id}', [AdminWarehouseController::class, 'update']);
    Route::post('/admin/warehouse/updateStatus', [AdminWarehouseController::class, 'updateStatus']);
    Route::post('/admin/warehouse/delete/{id}', [AdminWarehouseController::class, 'destroy']);
    Route::get('/admin/warehouse/goods/{id}', [AdminWarehouseController::class, 'getWarehouseGoods']);

    // 入库管理路由
    Route::get('/warehouse/stock-in/list', [WarehouseStockInController::class, 'index']);
    // 仓库入库管理
    Route::get('/warehouse/stock-in', [WarehouseStockInController::class, 'index']);
    Route::post('/warehouse/stock-in/batch-create', [WarehouseStockInController::class, 'batchCreate']);
    Route::post('/warehouse/stock-in/create', [WarehouseStockInController::class, 'store']);
    Route::post('/warehouse/stock-in/import', [WarehouseStockInController::class, 'batchCreate']);
    Route::post('/warehouse/stock-in/cancel/{id}', [WarehouseStockInController::class, 'cancel']);
    Route::delete('/warehouse/stock-in/{id}', [WarehouseStockInController::class, 'destroy']);
    Route::delete('/warehouse/inbound/batch', [WarehouseStockInController::class, 'batchDestroy']);

    // 结算入库记录
    Route::post('/warehouse/inbound/settle/{id}', [WarehouseStockInController::class, 'settle']);

    // 重置入库记录结算状态
    Route::post('/warehouse/inbound/reset-settle/{id}', [WarehouseStockInController::class, 'resetSettle']);
    // 预报管理
    Route::prefix('forecast')->group(function () {
        Route::get('list', [WarehouseForecastController::class, 'index']);
        Route::post('add', [WarehouseForecastController::class, 'store']);
        Route::post('cancel/{id}', [WarehouseForecastController::class, 'cancel']);
        Route::delete('delete/{id}', [WarehouseForecastController::class, 'destroy']);
        Route::delete('batch/delete', [WarehouseForecastController::class, 'batchDestroy']);
        Route::post('check-order-no-exists', [WarehouseForecastController::class, 'checkOrderNoExists']);
    });

    // 库存管理相关路由
    Route::prefix('warehouse/stock')->group(function () {
        // 获取库存列表
        Route::get('list', [StockController::class, 'list']);

        // 批量导入库存
        Route::post('import', [StockController::class, 'import']);

        // 匹配预报
        Route::post('match', [StockController::class, 'match']);

        // 确认入库
        Route::post('confirm/{id}', [StockController::class, 'confirm'])
            ->where('id', '[0-9]+');

        // 获取预报详情
        Route::get('forecast/{id}', [StockController::class, 'getForecastDetail'])
            ->where('id', '[0-9]+');

        // 结算库存
        Route::post('settle', [StockController::class, 'settle']);

        // 批量删除
        Route::delete('batch', [StockController::class, 'batchDelete']);

        // 检查快递单号是否存在
        Route::post('check-exists', [StockController::class, 'checkTrackingNoExists']);
    });

    // 预报队列管理
    Route::post('/warehouse/forecast/batch-add-to-crawler-queue', [WarehouseForecastController::class, 'batchAddToForecastCrawlerQueue']);

    // 卡密相关路由
    Route::prefix('gift-card')->group(function () {
        // 设定卡密查询规则
        Route::post('/set-query-rule', [GiftCardController::class, 'setQueryRule']);
        // 批量查询卡密
        Route::post('/batch-query', [GiftCardController::class, 'batchQuery']);
        // 处理兑换消息
        Route::post('/exchange', 'App\Http\Controllers\Api\GiftCardExchangeController@processExchangeMessage');
        
        // 验证礼品卡
        Route::post('/validate', 'App\Http\Controllers\Api\GiftCardExchangeController@validateGiftCard');
        
        // 获取兑换记录
        Route::get('/records', 'App\Http\Controllers\Api\GiftCardExchangeController@getExchangeRecords');
        
        // 获取单个兑换记录详情
        Route::get('/records/{id}', 'App\Http\Controllers\Api\GiftCardExchangeController@getExchangeRecord');
        
        // 账号登录管理
        Route::post('/login/new', 'App\Http\Controllers\Api\GiftCardExchangeController@createLoginTask');
        Route::get('/login/status', 'App\Http\Controllers\Api\GiftCardExchangeController@getLoginTaskStatus');
        Route::post('/login/delete', 'App\Http\Controllers\Api\GiftCardExchangeController@deleteUserLogins');
        Route::post('/login/refresh', 'App\Http\Controllers\Api\GiftCardExchangeController@refreshUserLogin');
        
        // 批量查询卡
        Route::post('/query/new', 'App\Http\Controllers\Api\GiftCardExchangeController@queryCards');
        Route::get('/query/status', 'App\Http\Controllers\Api\GiftCardExchangeController@getCardQueryTaskStatus');
        Route::post('/query/history', 'App\Http\Controllers\Api\GiftCardExchangeController@getCardQueryHistory');
        
        // 批量兑换卡
        Route::post('/redeem/new', 'App\Http\Controllers\Api\GiftCardExchangeController@redeemCards');
        Route::get('/redeem/status', 'App\Http\Controllers\Api\GiftCardExchangeController@getRedemptionTaskStatus');
        Route::post('/redeem/history', 'App\Http\Controllers\Api\GiftCardExchangeController@getRedemptionHistory');
    });

    // 微信账单记录
    Route::prefix('wechat/bill')->group(function () {
        // 批量查询微信账单记录
        // Route::post('/batch-query', [WechatBillController::class, 'batchQuery']);
    });
    // 微信机器人相关路由
    Route::prefix('wechat-bot')->group(function () {
       // 检测机器人心跳
        Route::post('heartbeat', function () {
            $result = check_bot_heartbeat();
            var_dump($result);exit;
        });
    });

    // iTunes Trade API Routes
    Route::prefix('trade/itunes')->group(function () {
        // 国家配置接口
        Route::get('/configs', 'App\Http\Controllers\Api\ItunesTradeController@getConfigs');
        Route::get('/configs/{countryCode}', 'App\Http\Controllers\Api\ItunesTradeController@getConfig');
        Route::post('/configs', 'App\Http\Controllers\Api\ItunesTradeController@saveConfig');
        Route::put('/configs/{id}', 'App\Http\Controllers\Api\ItunesTradeController@updateConfig');
        Route::delete('/configs/{id}', 'App\Http\Controllers\Api\ItunesTradeController@deleteConfig');

        // 模板接口
        Route::get('/templates', 'App\Http\Controllers\Api\ItunesTradeController@getTemplates');
        Route::post('/templates', 'App\Http\Controllers\Api\ItunesTradeController@saveTemplate');
        Route::post('/templates/{id}/apply', 'App\Http\Controllers\Api\ItunesTradeController@applyTemplate');
    });

    // Gift Exchange API Routes
    Route::prefix('trade/gift-exchange')->group(function () {
        // Plan routes
        Route::get('/plans', 'App\Http\Controllers\Api\GiftExchangeController@getPlans');
        Route::get('/plans/{id}', 'App\Http\Controllers\Api\GiftExchangeController@getPlan');
        Route::post('/plans', 'App\Http\Controllers\Api\GiftExchangeController@savePlan');
        Route::post('/plans/batch', 'App\Http\Controllers\Api\GiftExchangeController@batchCreatePlans');
        Route::post('/plans/from-template', 'App\Http\Controllers\Api\GiftExchangeController@createPlanFromTemplate');
        Route::put('/plans/{id}', 'App\Http\Controllers\Api\GiftExchangeController@updatePlan');
        Route::put('/plans/{id}/status', 'App\Http\Controllers\Api\GiftExchangeController@updatePlanStatus');
        Route::delete('/plans/{id}', 'App\Http\Controllers\Api\GiftExchangeController@deletePlan');
        Route::post('/plans/{id}/execute', 'App\Http\Controllers\Api\GiftExchangeController@executePlan');
        Route::post('/plans/{id}/pause', 'App\Http\Controllers\Api\GiftExchangeController@pausePlan');
        Route::post('/plans/{id}/resume', 'App\Http\Controllers\Api\GiftExchangeController@resumePlan');
        Route::post('/plans/{id}/cancel', 'App\Http\Controllers\Api\GiftExchangeController@cancelPlan');
        Route::get('/plans/{id}/logs', 'App\Http\Controllers\Api\GiftExchangeController@getPlanLogs');
        
        // Template routes
        Route::get('/templates', 'App\Http\Controllers\Api\GiftExchangeController@getTemplates');
        Route::post('/templates', 'App\Http\Controllers\Api\GiftExchangeController@savePlanAsTemplate');
        
        // Account group routes
        Route::get('/account-groups', 'App\Http\Controllers\Api\GiftExchangeController@getAccountGroups');
        Route::get('/account-groups/{id}', 'App\Http\Controllers\Api\GiftExchangeController@getAccountGroup');
        Route::post('/account-groups', 'App\Http\Controllers\Api\GiftExchangeController@createAccountGroup');
        Route::put('/account-groups/{id}', 'App\Http\Controllers\Api\GiftExchangeController@updateAccountGroup');
        Route::delete('/account-groups/{id}', 'App\Http\Controllers\Api\GiftExchangeController@deleteAccountGroup');
        Route::post('/account-groups/{id}/plans', 'App\Http\Controllers\Api\GiftExchangeController@addPlansToGroup');
        Route::delete('/account-groups/{id}/plans', 'App\Http\Controllers\Api\GiftExchangeController@removePlansFromGroup');
        Route::put('/account-groups/{id}/priorities', 'App\Http\Controllers\Api\GiftExchangeController@updatePlanPriorities');
        Route::post('/account-groups/{id}/start', 'App\Http\Controllers\Api\GiftExchangeController@startAccountGroup');
        Route::post('/account-groups/{id}/pause', 'App\Http\Controllers\Api\GiftExchangeController@pauseAccountGroup');
        
        // Auto execution routes
        Route::get('/auto-execution/status', 'App\Http\Controllers\Api\GiftExchangeController@getAutoExecutionStatus');
        Route::put('/auto-execution/settings', 'App\Http\Controllers\Api\GiftExchangeController@updateAutoExecutionSettings');
        
        // Account balance limit routes
        Route::get('/account-limits', 'App\Http\Controllers\Api\AccountBalanceLimitController@getBalanceLimits');
        Route::get('/account-limits/{id}', 'App\Http\Controllers\Api\AccountBalanceLimitController@getBalanceLimit');
        Route::post('/account-limits', 'App\Http\Controllers\Api\AccountBalanceLimitController@createBalanceLimit');
        Route::post('/account-limits/batch', 'App\Http\Controllers\Api\AccountBalanceLimitController@batchCreateBalanceLimits');
        Route::put('/account-limits/{id}', 'App\Http\Controllers\Api\AccountBalanceLimitController@updateBalanceLimit');
        Route::post('/account-limits/{id}/reset', 'App\Http\Controllers\Api\AccountBalanceLimitController@resetBalance');
        Route::put('/account-limits/{id}/status', 'App\Http\Controllers\Api\AccountBalanceLimitController@updateStatus');
        Route::delete('/account-limits/{id}', 'App\Http\Controllers\Api\AccountBalanceLimitController@deleteBalanceLimit');
    });
});


