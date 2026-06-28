# TASK-008: 邮件模板系统

**Sprint:** sprint-002  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现可定制的邮件模板引擎，支持租户级品牌化邮件发送。

---

## 范围

**允许修改：**
- `src/Services/MailTemplateService.php`（新建）
- `src/Models/MailTemplate.php`（新建）
- `database/migrations/` 下新增 mail_templates 迁移
- `src/Mail/TenantMail.php`（新建 Mailable）
- `config/tenancy.php`（追加邮件模板配置）
- `lang/zh_CN/notification.php`、`lang/en/notification.php`（新增翻译 key）
- `tests/MailTemplateServiceTest.php`（新建）
- `tests/TestCase.php`（追加新表 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### MailTemplateService

1. **模板 CRUD**: 创建、读取、更新、删除邮件模板
2. **变量替换**: 支持 `{{tenant_name}}`、`{{invoice_amount}}`、`{{user_name}}` 等占位符
3. **模板分类**: billing（账单）、notification（通知）、welcome（欢迎）、reset（重置）
4. **默认模板 + 租户覆盖**: 系统级默认模板（tenant_id=null），租户可自定义覆盖
5. **模板激活/停用**: 支持模板状态管理

### TenantMail

1. 继承 Laravel `Mailable` 基类
2. 自动注入租户上下文（租户名称、品牌色等）
3. 根据 MailTemplateService 获取对应模板
4. 支持 HTML 和纯文本两种格式
5. 支持附件

### 数据模型

1. `mail_templates` 表: 租户ID(null=系统默认)、模板类型、模板名称、主题、HTML正文(LONGTEXT)、纯文本正文(TEXT)、变量定义(JSON)、状态(activated/disabled)

### 预置系统模板

6 个系统默认模板（tenant_id=null）：

| 模板类型 | 名称 | 触发场景 |
|----------|------|----------|
| welcome | 欢迎注册 | 新租户注册成功 |
| reset | 密码重置 | 用户请求重置密码 |
| billing | 发票通知 | 发票开具后 |
| billing | 付款成功 | 支付成功后 |
| billing | 付款失败 | 支付失败后 |
| notification | 试用到期 | 试用期即将到期 |

### TestCase 补充

追加 `mail_templates` 表 schema。

---

## 验收标准

- [ ] MailTemplateService CRUD 功能正常
- [ ] 变量替换功能正常（`{{var}}` 格式）
- [ ] 租户自定义模板能覆盖系统默认模板
- [ ] TenantMail Mailable 可正常发送邮件
- [ ] 6 个预置系统模板已 seed 到数据库
- [ ] TestCase 追加新表 schema，`php vendor/bin/phpunit` 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- MailTemplate 模型 use `HasTenantScope`，但系统默认模板 tenant_id 为 null，需在 scope 中处理
- 变量替换用正则 `{{(\w+)}}` 匹配，从传入的 data 数组中取值
- TenantMail 应在 build() 方法中加载模板并渲染
- config/tenancy.php 追加 `mail_templates` 配置段，含默认模板路径
- 迁移文件命名: `2026_06_27_000013_create_mail_templates_table.php`
- 预置模板可通过 seeder 或 migration 的初始化数据插入
