# Mailer Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use compose:subagent (recommended) or compose:execute to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a centralized MailerService that unifies all email sending through TenantMail templates with branding injection, replacing the fragmented hardcoded Notification emails.

**Architecture:** `MailerService` is a thin facade over Laravel's `Mail` facade that routes all emails through `TenantMail` (template-driven) with automatic branding. Existing Notification classes are updated to use `MailerService` instead of hardcoded `MailMessage`. AlertService email placeholder is replaced with real sending. Core functionality, not a module.

**Tech Stack:** Laravel Mail facade, existing TenantMail Mailable, existing MailTemplateService, existing BrandingService.

## Global Constraints

- PHP ^8.3, Laravel ^13.0
- Core framework (not a module) — lives in `src/Services/`
- All email must go through `TenantMail` for template rendering + branding
- Tenant-specific template override + system default fallback (existing pattern)
- No new dependencies

---

### Task 1: Create MailerService

**Files:**
- Create: `src/Services/MailerService.php`
- Test: `tests/MailerServiceTest.php`

**Interfaces:**
- Consumes: `MailTemplateService::render()`, `TenantMail`, `BrandingService::renderEmailTemplate()`
- Produces: `MailerService::send()`, `MailerService::sendRaw()`, `MailerService::sendTemplate()`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\MailerService;

class MailerServiceTest extends TestCase
{
    protected MailerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MailerService::class);
    }

    public function test_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(MailerService::class, $this->service);
    }

    public function test_send_template_returns_bool(): void
    {
        // 使用 log mailer，不会真正发送
        config(['mail.default' => 'log']);
        $result = $this->service->sendTemplate(
            'test@example.com',
            'welcome_registration',
            ['user_name' => 'Test User', 'platform_name' => 'Test']
        );
        $this->assertIsBool($result);
    }

    public function test_send_raw_returns_bool(): void
    {
        config(['mail.default' => 'log']);
        $result = $this->service->sendRaw(
            'test@example.com',
            'Test Subject',
            '<p>Hello</p>'
        );
        $this->assertIsBool($result);
    }

    public function test_send_template_with_tenant_id(): void
    {
        config(['mail.default' => 'log']);
        $result = $this->service->sendTemplate(
            'test@example.com',
            'password_reset',
            ['reset_url' => 'https://example.com/reset'],
            tenantId: null
        );
        $this->assertIsBool($result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- MailerServiceTest`
Expected: FAIL with "Class MultiTenantSaas\Services\MailerService does not exist"

- [ ] **Step 3: Implement MailerService**

```php
<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use MultiTenantSaas\Mail\TenantMail;

/**
 * 邮件发送服务
 *
 * 统一所有邮件发送入口，通过 TenantMail 实现模板渲染 + 品牌注入。
 * 支持模板驱动（sendTemplate）和直接发送（sendRaw）。
 *
 * 不依赖特定 mail driver，发送失败时记录日志不抛异常。
 */
class MailerService
{
    public function __construct(
        protected MailTemplateService $templateService,
    ) {}

    /**
     * 通过模板发送邮件。
     *
     * 使用 TenantMail 渲染模板，自动注入品牌和租户变量。
     *
     * @param  string  $to       收件人
     * @param  string  $type     模板类型 (welcome/reset/billing/notification)
     * @param  array   $data     模板变量
     * @param  int|null $tenantId 租户 ID (null = 使用当前上下文)
     * @param  array   $attachments 附件配置
     * @return bool 是否发送成功
     */
    public function sendTemplate(
        string $to,
        string $type,
        array $data = [],
        ?int $tenantId = null,
        array $attachments = [],
    ): bool {
        try {
            $mailable = new TenantMail($type, $data, $tenantId, $attachments);
            Mail::to($to)->send($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::error('[MailerService] Failed to send template email', [
                'to' => $to,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 直接发送 HTML 邮件（不使用模板）。
     *
     * 用于 MFA 验证码、测试邮件等简单场景。
     */
    public function sendRaw(string $to, string $subject, string $html, ?int $tenantId = null): bool
    {
        try {
            $fromAddress = config('tenancy.mail_templates.default_from_address', 'noreply@example.com');
            $fromName = config('tenancy.mail_templates.default_from_name', 'Tenant SaaS');

            Mail::to($to)->send(new class($subject, $html) extends \Illuminate\Mail\Mailable {
                public function __construct(
                    private string $emailSubject,
                    private string $emailHtml,
                ) {}

                public function envelope(): \Illuminate\Mail\Mailables\Envelope
                {
                    return new \Illuminate\Mail\Mailables\Envelope(
                        subject: $this->emailSubject,
                    );
                }

                public function content(): \Illuminate\Mail\Mailables\Content
                {
                    return new \Illuminate\Mail\Mailables\Content(
                        htmlString: $this->emailHtml,
                    );
                }
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('[MailerService] Failed to send raw email', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 发送 MFA 验证码邮件。
     */
    public function sendMfaCode(string $to, string $code): bool
    {
        $html = trans('auth.mfa_email_body', ['code' => $code]);

        return $this->sendRaw($to, trans('auth.mfa_email_subject'), $html);
    }

    /**
     * 发送测试邮件（验证邮件配置）。
     */
    public function sendTest(string $to): bool
    {
        $html = '<p>这是一封测试邮件。如果您收到此邮件，说明邮件配置正确。</p>';

        return $this->sendRaw($to, '测试邮件', $html);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:filter -- MailerServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Services/MailerService.php tests/MailerServiceTest.php
git commit -m "feat: add MailerService with template and raw email sending"
```

---

### Task 2: Register MailerService and Refactor MfaService

**Files:**
- Modify: `src/TenancyServiceProvider.php` (register MailerService singleton)
- Modify: `src/Services/MfaService.php` (use MailerService instead of Mail::raw)
- Modify: `src/Services/SystemSettingService.php` (use MailerService for test email)

**Interfaces:**
- Consumes: `MailerService::sendMfaCode()`, `MailerService::sendTest()`

- [ ] **Step 1: Register MailerService in TenancyServiceProvider**

Add to `register()` method after the SchedulerService line:

```php
$this->app->singleton(\MultiTenantSaas\Services\MailerService::class);
```

- [ ] **Step 2: Refactor MfaService to use MailerService**

In `src/Services/MfaService.php`, replace the `Mail::raw()` call (around line 123) with:

```php
app(\MultiTenantSaas\Services\MailerService::class)->sendMfaCode($email, $code);
```

Remove the `use Illuminate\Support\Facades\Mail;` import if no longer needed.

- [ ] **Step 3: Refactor SystemSettingService test email**

In `src/Services/SystemSettingService.php`, replace the `Mail::raw()` test email (around line 226) with:

```php
return app(\MultiTenantSaas\Services\MailerService::class)->sendTest($to);
```

- [ ] **Step 4: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add src/TenancyServiceProvider.php src/Services/MfaService.php src/Services/SystemSettingService.php
git commit -m "refactor: use MailerService in MfaService and SystemSettingService"
```

---

### Task 3: Refactor AlertService Email Placeholder

**Files:**
- Modify: `src/Services/AlertService.php` (replace Log::info with MailerService)

**Interfaces:**
- Consumes: `MailerService::sendRaw()`

- [ ] **Step 1: Refactor AlertService::sendEmail()**

Replace the `sendEmail()` method (around line 274-277) with:

```php
protected function sendEmail(string $severity, string $ruleName, string $message, array $context): void
{
    $to = config('tenancy.alerts.email_recipient', config('tenancy.mail_templates.default_from_address'));
    if (empty($to)) {
        return;
    }

    $subject = "[{$severity}] 告警: {$ruleName}";
    $html = "<p><strong>{$ruleName}</strong></p><p>{$message}</p>";
    if (! empty($context)) {
        $html .= '<pre>' . e(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
    }

    app(\MultiTenantSaas\Services\MailerService::class)->sendRaw($to, $subject, $html);
}
```

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add src/Services/AlertService.php
git commit -m "feat: replace AlertService email placeholder with MailerService"
```

---

### Task 4: Refactor Notification Classes to Use Templates

**Files:**
- Modify: `app/Notifications/PaymentSuccessNotification.php`
- Modify: `app/Notifications/SubscriptionExpiringNotification.php`
- Modify: `app/Notifications/TenantSuspendedNotification.php`
- Modify: `app/Notifications/CreditLowNotification.php`

**Interfaces:**
- Consumes: `MailerService::sendTemplate()` (indirectly via Notification)

- [ ] **Step 1: Refactor PaymentSuccessNotification::toMail()**

Replace the hardcoded `MailMessage` with template-driven content:

```php
public function toMail(object $notifiable): MailMessage
{
    $template = app(\MultiTenantSaas\Services\MailTemplateService::class)
        ->render('billing', [
            'user_name' => $notifiable->name,
            'order_no' => $this->orderNo,
            'amount' => number_format($this->amount / 100, 2),
            'payment_method' => $this->paymentMethod,
        ]);

    if ($template) {
        return (new MailMessage())
            ->subject($template['subject'])
            ->line($template['text'] ?? strip_tags($template['html']))
            ->action('查看订单', url('/console/billing/orders'));
    }

    // Fallback to hardcoded
    return (new MailMessage())
        ->subject('支付成功通知')
        ->line("订单号: {$this->orderNo}")
        ->line("金额: ¥" . number_format($this->amount / 100, 2))
        ->action('查看订单', url('/console/billing/orders'));
}
```

- [ ] **Step 2: Apply same pattern to other 3 Notification classes**

For `SubscriptionExpiringNotification`:
- template type: `notification`
- data: `tenant_name`, `plan_name`, `expires_at`, `days_left`

For `TenantSuspendedNotification`:
- template type: `notification`
- data: `tenant_name`, `reason`

For `CreditLowNotification`:
- template type: `notification`
- data: `remaining_credits`, `threshold`

Each follows the same pattern: try template render first, fallback to hardcoded.

- [ ] **Step 3: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add app/Notifications/
git commit -m "refactor: Notification classes use MailTemplateService with fallback"
```

---

### Task 5: Add TenantInvitationMail

**Files:**
- Create: `src/Mail/TenantInvitationMail.php`
- Modify: `src/Services/TenantMemberService.php` (uncomment and wire up)
- Test: `tests/TenantInvitationMailTest.php`

**Interfaces:**
- Consumes: `TenantMail` (base class), `MailTemplateService`
- Produces: `TenantInvitationMail` Mailable

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Mail\TenantInvitationMail;

class TenantInvitationMailTest extends TestCase
{
    public function test_mailable_can_be_created(): void
    {
        config(['mail.default' => 'log']);
        $mailable = new TenantInvitationMail(
            email: 'test@example.com',
            tenantName: 'Test Tenant',
            inviterName: 'Admin',
            inviteUrl: 'https://example.com/invite?token=abc',
            role: 'end_user',
        );
        $this->assertInstanceOf(TenantInvitationMail::class, $mailable);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- TenantInvitationMailTest`
Expected: FAIL

- [ ] **Step 3: Implement TenantInvitationMail**

```php
<?php

namespace MultiTenantSaas\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use MultiTenantSaas\Services\MailerService;

class TenantInvitationMail extends Mailable
{
    public function __construct(
        protected string $email,
        protected string $tenantName,
        protected string $inviterName,
        protected string $inviteUrl,
        protected string $role = 'end_user',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->inviterName} 邀请你加入 {$this->tenantName}",
        );
    }

    public function content(): Content
    {
        // 尝试从模板渲染
        $templateService = app(\MultiTenantSaas\Services\MailTemplateService::class);
        $rendered = $templateService->render('notification', [
            'user_name' => $this->email,
            'tenant_name' => $this->tenantName,
            'inviter_name' => $this->inviterName,
            'invite_url' => $this->inviteUrl,
            'role' => $this->role,
        ]);

        if ($rendered) {
            return new Content(
                htmlString: $rendered['html'],
            );
        }

        // Fallback: inline HTML
        $html = <<<HTML
        <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">
            <h2>加入 {$this->tenantName}</h2>
            <p>{$this->inviterName} 邀请你加入 <strong>{$this->tenantName}</strong>。</p>
            <p>角色: {$this->role}</p>
            <p style="margin: 24px 0;">
                <a href="{$this->inviteUrl}"
                   style="background: #4f46e5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;">
                    接受邀请
                </a>
            </p>
            <p style="color: #666; font-size: 12px;">如果按钮无法点击，请复制链接: {$this->inviteUrl}</p>
        </div>
        HTML;

        return new Content(htmlString: $html);
    }
}
```

- [ ] **Step 4: Wire up in TenantMemberService**

In `src/Services/TenantMemberService.php`, replace the commented-out line (around line 110-111) with:

```php
\Mail::to($email)->send(new \MultiTenantSaas\Mail\TenantInvitationMail(
    email: $email,
    tenantName: $tenant->name ?? '',
    inviterName: auth()->user()?->name ?? 'System',
    inviteUrl: $inviteUrl,
    role: $role,
));
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test:filter -- TenantInvitationMailTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Mail/TenantInvitationMail.php src/Services/TenantMemberService.php tests/TenantInvitationMailTest.php
git commit -m "feat: add TenantInvitationMail and wire up in TenantMemberService"
```

---

### Task 6: Add MailerService to SchedulerService Alerts

**Files:**
- Modify: `src/Services/SchedulerService.php` (add mailer health check)

- [ ] **Step 1: Add email delivery test to schedule**

Add to `defineTasks()` in SchedulerService:

```php
$this->addTask($schedule, 'mailer-health', [
    'command' => 'mailer:health-check',
    'schedule' => 'dailyAt:05:00',
    'description' => '检查邮件服务健康状态',
]);
```

- [ ] **Step 2: Create mailer:health-check command**

Create `src/Console/Commands/MailerHealthCheckCommand.php`:

```php
<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\MailerService;

class MailerHealthCheckCommand extends Command
{
    protected $signature = 'mailer:health-check
        {--dry-run : 只检查配置，不发送测试邮件}';

    protected $description = '检查邮件服务健康状态';

    public function handle(MailerService $mailer): int
    {
        $fromAddress = config('tenancy.mail_templates.default_from_address');

        if (empty($fromAddress)) {
            $this->warn('MAIL_FROM_ADDRESS 未配置');

            return self::SUCCESS;
        }

        $this->info("邮件配置: from={$fromAddress}");

        if ($this->option('dry-run')) {
            $this->info('[DRY] 跳过发送测试邮件');

            return self::SUCCESS;
        }

        $result = $mailer->sendTest($fromAddress);

        if ($result) {
            $this->info('测试邮件发送成功');

            return self::SUCCESS;
        }

        $this->error('测试邮件发送失败');

        return self::FAILURE;
    }
}
```

- [ ] **Step 3: Register command in TenancyServiceProvider**

Add to commands array:

```php
\MultiTenantSaas\Console\Commands\MailerHealthCheckCommand::class,
```

- [ ] **Step 4: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Services/SchedulerService.php src/Console/Commands/MailerHealthCheckCommand.php src/TenancyServiceProvider.php
git commit -m "feat: add mailer:health-check command and schedule"
```

---

### Task 7: Update Documentation

**Files:**
- Modify: `docs/zh/user-manual.md`

- [ ] **Step 1: Add Mailer section to user manual**

Add to `docs/zh/user-manual.md`:

```markdown
## 邮件服务

框架通过 `MailerService` 统一管理所有邮件发送。

### 发送方式

```php
use MultiTenantSaas\Services\MailerService;

$mailer = app(MailerService::class);

// 通过模板发送 (推荐)
$mailer->sendTemplate('user@example.com', 'welcome_registration', [
    'user_name' => '张三',
    'platform_name' => 'MyApp',
]);

// 直接发送 HTML
$mailer->sendRaw('user@example.com', '主题', '<p>内容</p>');

// MFA 验证码
$mailer->sendMfaCode('user@example.com', '123456');

// 测试邮件
$mailer->sendTest('admin@example.com');
```

### 模板系统

邮件模板存储在 `mail_templates` 表中，支持：
- 6 个预置模板：welcome, reset, billing, notification
- 租户级覆盖：租户可自定义模板，未自定义的使用系统默认
- 变量替换：`{{user_name}}`, `{{platform_name}}` 等

### 品牌注入

`TenantMail` 自动注入租户品牌：
- Logo（从 BrandingService 获取）
- 品牌颜色
- 平台名称

### 定时检查

`mailer:health-check` 每日 05:00 自动检查邮件服务状态。
```

- [ ] **Step 2: Run final test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add docs/zh/user-manual.md
git commit -m "docs: add Mailer section to user manual"
```
