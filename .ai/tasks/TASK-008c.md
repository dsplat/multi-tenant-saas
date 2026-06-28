# TASK-008c: [Auto-split from TASK-008]


**目标:** 实现 TenantMail Mailable，支持从数据库模板渲染邮件、注入租户上下文、HTML/纯文本双格式和附件

**只允许修改:**
- `src/Mail/TenantMail.php`（新建）

**禁止:** 修改其他文件、新增依赖

**预估时间:** 1.5 小时

**依赖:** TASK-008b

**具体交付物:**
1. **TenantMail Mailable：**
   - 继承 `Illuminate\Mail\Mailable`
   - 使用 `Queueable`, `SerializesModels` traits
   - 构造函数参数: `string $templateType`, `array $data = []`, `?int $tenantId = null`, `array $attachments = []`
   - 公开属性: `$templateType`, `$data`, `$tenantId`, `$attachments`
   - `envelope()` 方法: 调用 `MailTemplateService::render()` 获取渲染后的 subject，返回 `Mailables\Envelope`
   - `content()` 方法: 调用 `MailTemplateService::render()` 获取渲染后的 html_body/text_body，使用 `htmlString:` 返回 `Mailables\Content`（与现有 Mail 类风格一致）
   - `attachments()` 方法: 返回附件数组
   - 自动注入租户上下文: 从 `TenantContext` 获取租户名称、品牌色等，合并到 `$data` 数组中作为默认变量
   - 默认变量: `{{tenant_name}}`, `{{tenant_brand_color}}`, `{{current_year}}`, `{{platform_name}}`

---



## 状态
READY
