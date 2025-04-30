<?php

use App\Http\Controllers\AdminMenuController;
use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\InvitationCodeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\WarehouseForecastController;
use App\Http\Controllers\WarehouseGoodsController;
use App\Http\Controllers\AdminWarehouseController;
use App\Http\Controllers\WarehouseStockInController;
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
        Route::post('settle/{id}', [StockController::class, 'settle'])
            ->where('id', '[0-9]+');

        // 批量删除
        Route::delete('batch', [StockController::class, 'batchDelete']);

        // 检查快递单号是否存在
        Route::post('check-exists', [StockController::class, 'checkTrackingNoExists']);
    });

    Route::post('/warehouse/forecast/batch-add-to-crawler-queue', [WarehouseForecastController::class, 'batchAddToForecastCrawlerQueue']);
});
