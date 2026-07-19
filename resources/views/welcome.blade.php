<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Multi-Tenant SaaS') }}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
        .container { text-align: center; padding: 2rem; background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-bottom: 0.5rem; }
        p { color: #666; }
        .badge { display: inline-block; padding: 0.25rem 0.75rem; background: #e3f2fd; color: #1976d2; border-radius: 4px; font-size: 0.875rem; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Multi-Tenant SaaS</h1>
        <p>Laravel 多团队 SaaS 基础框架</p>
        <span class="badge">Tenant ID: {{ tenant_id() ?? '未识别' }}</span>
    </div>
</body>
</html>
