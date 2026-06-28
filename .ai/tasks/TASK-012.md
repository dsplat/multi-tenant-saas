# TASK-012: 图片 AI 服务

**Sprint:** sprint-003  
**状态:** READY  
**依赖:** TASK-010（AiGatewayService）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现图片生成 AI 能力，接入 OpenAI DALL-E 和 Stability AI Stable Diffusion，支持文生图、图生图、图片编辑和风格迁移。

---

## 范围

**只允许修改：**
- `src/Services/AiImageService.php`（新建）
- `src/Services/Ai/DalleProvider.php`（新建）
- `src/Services/Ai/StableDiffusionProvider.php`（新建）
- `config/ai.php`（追加图片 AI 配置）
- `lang/zh_CN/ai.php`、`lang/en/ai.php`（追加翻译 key）
- `tests/AiImageServiceTest.php`（新建）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### AiImageService

1. 文生图(text-to-image)
2. 图生图(image-to-image)
3. 图片编辑(Inpainting/Outpainting)
4. 风格迁移
5. 尺寸/质量控制
6. 结果存储：自动关联 FileUploadService

### DalleProvider

DALL-E 3 API：1024×1024/1792×1024/1024×1792，quality(standard/hd)，style(vivid/natural)

### StableDiffusionProvider

Stability AI API：SD3/SDXL，negative prompt、seed、steps、CFG scale

### 集成

- 请求通过 AiGatewayService 记录到 ai_requests 表
- 记录图片尺寸/数量/费用
- 结果通过 FileUploadService 存储

> **⚠ 文件共享警告**: 与 TASK-011/013/014b 共享 config/ai.php 和 lang 文件。建议串行执行。

---

## 验收标准

- [ ] 文生图功能正常（两个提供商）
- [ ] 图生图功能正常
- [ ] 图片编辑/风格迁移功能正常
- [ ] 生成结果通过 FileUploadService 存储并返回 URL
- [ ] 请求记录在 ai_requests 表
- [ ] phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- Provider 放在 src/Services/Ai/ 目录
- AiImageService 通过 AiGatewayService 调用提供商
- 调用现有 FileUploadService 进行文件存储
- 测试中 mock HTTP 请求
---

## 全局规范声明

> **⚠ 严格遵守全局约束 — 此部分适用于本任务的所有子任务（a/b/c/d...），无例外**

### 1. 禁止修改的文件

- **`.ai/scripts/` 目录下任何文件**（loop-run.sh、parallel-run.sh、loop-watch.sh、plan-task.sh、lib.sh 等）
- **`.ai/prompts/` 目录下任何文件**（dev-prompt.md、review-prompt.md、plan-prompt.md 等）
- 如 AI 在执行过程中发现需要修改上述文件，应**停止并向用户报告**，而不是自行修改

### 2. 编码规范

- 遵循 **PSR-12** 规范，使用 **Laravel 最佳实践**
- 所有 Controller 必须使用 **API Resource** 返回数据，禁止直接返回模型或数组
- 敏感字段（password/token/secret/key）**永不返回**，手机号脱敏
- 所有方法参数必须有**类型声明**，所有方法必须有**返回值类型声明**
- 使用 PHP 8.1+ 特性（枚举、只读属性等）
- 使用中文注释 + PHPDoc

### 3. 多语言规范

- 使用 `trans()` / `__()` 函数实现多语言，**禁止硬编码中文字符串**
- 新增翻译 key 必须同时添加到 `lang/zh_CN/` 和 `lang/en/` 两个目录

### 4. 数据库规范

- 迁移文件命名接续现有序号（查看 `database/migrations/` 最大序号后 +1）
- 新建模型 use `HasTenantScope` trait 实现租户隔离
- Service 类通过 `TenancyServiceProvider` 注册为 singleton

### 5. 响应格式

- 统一用 `ApiResponse::success()` 和 `ApiResponse::error()`
- 错误码标准化，HTTP 状态码正确

### 6. 测试规范

- 每个新建 Service 必须有对应的 Test 文件
- 测试继承 `tests/TestCase.php`，如需新表 schema 在 TestCase.php 中追加
- `php vendor/bin/phpunit` 全绿（预存在的失败除外，但不得新增失败）
