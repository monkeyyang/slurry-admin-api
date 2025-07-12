<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slurry Admin API</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .welcome-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }
        .btn-gradient {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            color: white;
        }
        .btn-gradient:hover {
            background: linear-gradient(45deg, #764ba2, #667eea);
            color: white;
            transform: translateY(-2px);
            transition: all 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="welcome-card p-5 text-center">
                    <h1 class="display-4 mb-4">
                        <i class="fas fa-cogs text-primary"></i>
                        Slurry Admin API
                    </h1>
                    <p class="lead mb-4">
                        欢迎使用 Slurry Admin API 系统
                    </p>
                    <hr class="my-4">
                    <div class="row text-center">
                        <div class="col-md-6 mb-3">
                            <h5><i class="fas fa-comments text-info"></i> 微信监控</h5>
                            <p>实时监控微信消息发送状态</p>
                            <a href="/wechat/monitor" class="btn btn-gradient btn-sm">
                                <i class="fas fa-chart-line"></i> 进入监控面板
                            </a>
                        </div>
                        <div class="col-md-6 mb-3">
                            <h5><i class="fas fa-api text-success"></i> API文档</h5>
                            <p>查看API接口文档和测试</p>
                            <a href="/api" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-book"></i> API接口
                            </a>
                        </div>
                    </div>
                    <div class="mt-4">
                        <small class="text-muted">
                            <i class="fas fa-clock"></i> 
                            系统时间: {{ now()->format('Y-m-d H:i:s') }}
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
</body>
</html>
