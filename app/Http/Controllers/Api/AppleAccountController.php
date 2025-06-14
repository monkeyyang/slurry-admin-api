<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\YokeAppleAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AppleAccountController extends Controller
{
    public function list()
    {

    }

    /**
     * 批量查询Apple账户信息
     */
    public function batchQuery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:txt|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '文件验证失败',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $file = $request->file('file');
            $content = file_get_contents($file->getPathname());

            // 按行分割并过滤空行
            $usernames = array_filter(array_map('trim', explode("\n", $content)));

            if (empty($usernames)) {
                return response()->json([
                    'success' => false,
                    'message' => '文件内容为空'
                ], 400);
            }

            $results = [];

            foreach ($usernames as $username) {
                if (empty($username)) continue;

                // 查询账户信息
                $account = YokeAppleAccount::where('username', $username)
                    ->select('username', 'new_password', 'new_security', 'birthday')
                    ->first();

                if ($account) {
                    $results[] = [
                        'username' => $account->username,
                        'new_password' => $account->new_password ?: '-',
                        'new_security' => $account->new_security ?: '-',
                        'birthday' => $account->birthday ?: '-'
                    ];
                } else {
                    // 如果账户不存在，所有字段都用-表示
                    $results[] = [
                        'username' => $username,
                        'new_password' => '-',
                        'new_security' => '-',
                        'birthday' => '-'
                    ];
                }
            }

            // 生成txt内容
            $txtContent = '';
            foreach ($results as $result) {
                $line = sprintf("%s\t%s\t%s\t%s\n",
                    $result['username'],
                    $result['new_password'],
                    $result['new_security'],
                    $result['birthday']
                );
                $txtContent .= $line;
            }

            // 返回文件下载
            $filename = 'apple_accounts_' . date('Y-m-d_H-i-s') . '.txt';

            return response($txtContent)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '查询失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取单个Apple账户信息
     */
    public function getAccount(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '参数验证失败',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $account = YokeAppleAccount::where('username', $request->username)
                ->select('username', 'new_password', 'new_security', 'birthday')
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => '账户不存在'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'username' => $account->username,
                    'new_password' => $account->new_password ?: '-',
                    'new_security' => $account->new_security ?: '-',
                    'birthday' => $account->birthday ?: '-'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '查询失败：' . $e->getMessage()
            ], 500);
        }
    }
}
