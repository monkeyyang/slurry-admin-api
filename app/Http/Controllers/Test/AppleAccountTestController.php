<?php

namespace App\Http\Controllers\Test;

use App\Http\Controllers\Controller;
use App\Models\YokeAppleAccount;
use Illuminate\Http\Request;

class AppleAccountTestController extends Controller
{
    /**
     * 测试Apple账户批量查询
     */
    public function testBatchQuery()
    {
        // 创建一个测试txt文件内容
        $testContent = "katieggzbennettecy@gmail.com\nblakebqqrobinsonat3@gmail.com\nerinvfqellisvha@gmail.com\nlily8qmlopez3hv@gmail.com\nfaith8q7robinsonwna@gmail.com";
        
        // 按行分割并过滤空行
        $usernames = array_filter(array_map('trim', explode("\n", $testContent)));
        
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

        // 生成txt格式的响应
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

        // 返回结果
        return response()->json([
            'success' => true,
            'message' => '查询完成',
            'data' => $results,
            'txt_content' => $txtContent,
            'total_count' => count($results)
        ]);
    }

    /**
     * 获取数据库中的示例账户
     */
    public function getSampleAccounts()
    {
        try {
            $accounts = YokeAppleAccount::select('username', 'new_password', 'new_security', 'birthday')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $accounts->map(function($account) {
                    return [
                        'username' => $account->username,
                        'new_password' => $account->new_password ?: '-',
                        'new_security' => $account->new_security ?: '-',
                        'birthday' => $account->birthday ?: '-'
                    ];
                }),
                'total_count' => $accounts->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '查询失败：' . $e->getMessage()
            ], 500);
        }
    }
} 