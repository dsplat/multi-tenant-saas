# 贡献指南

感谢您对 Multi-Tenant SaaS Framework 的关注！我们欢迎任何形式的贡献。

## 如何贡献

### 报告问题

1. 使用 [GitHub Issues](https://github.com/luoyueliang/multi-tenant-saas/issues) 报告问题
2. 使用问题模板，包含：
   - 问题描述
   - 复现步骤
   - 期望行为
   - 实际行为
   - 环境信息（PHP 版本、Laravel 版本等）

### 提交代码

1. Fork 本仓库
2. 创建特性分支：`git checkout -b feature/your-feature`
3. 提交更改：`git commit -m 'feat: 添加某个功能'`
4. 推送分支：`git push origin feature/your-feature`
5. 创建 Pull Request

### 提交规范

使用 [Conventional Commits](https://www.conventionalcommits.org/zh-hans/) 规范：

```
<type>(<scope>): <description>

[optional body]

[optional footer(s)]
```

**类型（type）**：
- `feat`: 新功能
- `fix`: Bug 修复
- `docs`: 文档更新
- `style`: 代码格式（不影响功能）
- `refactor`: 重构（不改变功能）
- `test`: 测试相关
- `chore`: 构建/工具/依赖

**示例**：
```
feat(tenant): 添加租户配额管理功能
fix(auth): 修复登录 token 过期问题
docs(api): 更新 API 文档
```

## 开发环境

### 环境要求

- PHP 8.2+
- Composer 2.0+
- MySQL 8.0+ 或 PostgreSQL 15+

### 安装步骤

```bash
# 克隆仓库
git clone https://github.com/luoyueliang/multi-tenant-saas.git
cd multi-tenant-saas

# 安装依赖
composer install

# 复制配置文件
cp .env.example .env

# 生成密钥
php artisan key:generate

# 运行迁移
php artisan migrate

# 运行测试
php artisan test
```

### 代码规范

- 遵循 [PSR-12](https://www.php-fig.org/psr/psr-12/) 规范
- 使用 Laravel 最佳实践
- 所有方法必须有类型声明
- 关键逻辑必须有注释

### 测试

```bash
# 运行所有测试
php artisan test

# 运行特定测试
php artisan test --filter=UserControllerTest

# 运行测试并生成覆盖率报告
php artisan test --coverage
```

## 架构说明

### 目录结构

```
├── app/
│   ├── Http/
│   │   ├── Controllers/    # 控制器
│   │   ├── Middleware/      # 中间件
│   │   └── Resources/      # API Resource
│   └── ...
├── src/
│   ├── Concerns/           # Trait
│   ├── Context/            # 上下文管理
│   ├── Models/             # 模型
│   ├── Scopes/             # 查询作用域
│   ├── Services/           # 服务层
│   └── ...
├── tests/                  # 浔试
└── docs/                   # 文档
```

### 核心概念

1. **租户隔离**：通过 `TenantScope` 和 `BelongsToTenant` 实现
2. **权限控制**：四重访问架构（系统后台 → 租户后台 → 用户前台 → 访客）
3. **配置管理**：`TenantSetting` 和 `SystemSetting` 分离
4. **API Resource**：统一响应格式，自动数据脱敏

## 行为准则

- 尊重所有贡献者
- 接受建设性批评
- 专注于对社区最有利的事情
- 对他人表示同理心

## 许可证

本项目基于 MIT 许可证开源。贡献代码即表示您同意您的贡献将在相同许可证下发布。
