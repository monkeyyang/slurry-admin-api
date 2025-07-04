#!/bin/bash

# 查码API测试脚本
# 使用方法: ./test_api_curl.sh [TOKEN]

API_URL="https://slurry-api.1105.me/verify/operation-logs/get-verify-code"
TOKEN=${1:-"YOUR_TOKEN_HERE"}

echo "=== 查码API测试 ==="
echo "API地址: $API_URL"
echo "Token: $TOKEN"
echo ""

# 测试数据
TEST_DATA='{
    "room_id": "test_room_123",
    "msgid": "test_msg_456", 
    "wxid": "test_wx_789",
    "accounts": [
        "furlongE2768@icloud.com",
        "lawsonP1217@icloud.com",
        "piersD3022@icloud.com"
    ]
}'

echo "请求数据:"
echo "$TEST_DATA"
echo ""

# 发送请求
echo "发送请求..."
curl -X POST "$API_URL" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -d "$TEST_DATA" \
  -w "\n\nHTTP状态码: %{http_code}\n响应时间: %{time_total}s\n" \
  -s

echo ""
echo "=== 测试完成 ===" 