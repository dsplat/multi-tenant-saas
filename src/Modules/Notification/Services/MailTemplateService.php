<?php

namespace MultiTenantSaas\Modules\Notification\Services;

use Illuminate\Database\Eloquent\Builder;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Notification\Models\MailTemplate;

/**
 * 邮件模板服务
 *
 * 负责邮件模板的 CRUD、变量替换、租户覆盖与系统默认回退、以及预置系统模板播种。
 *
 * - 系统默认模板: tenant_id IS NULL，所有租户共享
 * - 租户自定义模板: tenant_id = 指定租户，优先于系统默认
 * - findTemplate/render 显式接收 tenantId，绕过全局作用域以支持跨租户查找
 */
class MailTemplateService
{
    /**
     * 创建模板
     *
     * @param  array  $data  模板属性: type, name, subject, html_body, text_body, variables, status, tenant_id(可选)
     */
    public function create(array $data): MailTemplate
    {
        if (isset($data['type']) && ! in_array($data['type'], MailTemplate::TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid mail template type [%s]. Allowed: %s', $data['type'], implode(', ', MailTemplate::TYPES))
            );
        }

        return MailTemplate::create($data);
    }

    /**
     * 获取单个模板（受租户全局作用域约束，仅可见当前租户 + 系统默认）
     *
     * @throws \RuntimeException 模板不存在
     */
    public function get(int $id): MailTemplate
    {
        $template = MailTemplate::find($id);

        if (! $template) {
            throw new \RuntimeException(trans('notification.mail_templates.not_found'));
        }

        return $template;
    }

    /**
     * 更新模板
     *
     * @param  array  $data  可更新字段
     *
     * @throws \RuntimeException 模板不存在
     */
    public function update(int $id, array $data): MailTemplate
    {
        $template = $this->get($id);
        $template->update($data);

        return $template->fresh();
    }

    /**
     * 软删除模板
     *
     * @throws \RuntimeException 模板不存在
     */
    public function delete(int $id): bool
    {
        $template = $this->get($id);

        return (bool) $template->delete();
    }

    /**
     * 查找模板：优先返回租户自定义，回退到系统默认（tenant_id IS NULL）
     *
     * 绕过 mailTemplateTenant 全局作用域，以支持显式指定租户的跨租户查找。
     */
    public function findTemplate(string $type, ?int $tenantId = null): ?MailTemplate
    {
        $contextId = TenantContext::getId();
        $tenantId = $tenantId ?? ($contextId !== null ? (int) $contextId : null);

        $baseQuery = function () use ($type): Builder {
            return MailTemplate::withoutGlobalScope('mailTemplateTenant')
                ->where('type', $type)
                ->where('status', MailTemplate::STATUS_ACTIVATED);
        };

        if ($tenantId !== null) {
            $template = $baseQuery()
                ->where('tenant_id', $tenantId)
                ->orderBy('template_id')
                ->first();

            if ($template) {
                return $template;
            }
        }

        return $baseQuery()
            ->whereNull('tenant_id')
            ->orderBy('template_id')
            ->first();
    }

    /**
     * 查找模板并执行变量替换
     *
     * @return array{subject: string, html: string, text: string}|null
     */
    public function render(string $type, array $data, ?int $tenantId = null): ?array
    {
        $template = $this->findTemplate($type, $tenantId);

        if (! $template) {
            logger()->warning("Mail template not found: type={$type}");

            return null;
        }

        return [
            'subject' => $this->replaceVariables($template->subject, $data, true),
            'html' => $this->replaceVariables($template->html_body, $data, true),
            'text' => $this->replaceVariables($template->text_body ?? '', $data),
        ];
    }

    /**
     * 变量替换：用正则 {{(\w+)}} 匹配，从 $data 数组取值替换
     *
     * 缺失的变量保留原 {{var}} 占位符不变。
     */
    public function replaceVariables(string $content, array $data, bool $escape = false): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $matches) use ($data, $escape) {
            $key = $matches[1];

            if (! array_key_exists($key, $data)) {
                return $matches[0];
            }

            $value = (string) $data[$key];

            return $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
        }, $content) ?? $content;
    }

    /**
     * 切换模板激活/停用状态
     *
     * @param  string  $status  activated|disabled
     *
     * @throws \RuntimeException 模板不存在或状态非法
     */
    public function toggleStatus(int $id, string $status): MailTemplate
    {
        if (! in_array($status, MailTemplate::STATUSES, true)) {
            throw new \RuntimeException(trans('notification.mail_templates.invalid_status'));
        }

        $template = $this->get($id);
        $template->status = $status;
        $template->save();

        return $template;
    }

    /**
     * 播种 6 个系统默认模板（tenant_id = null）
     *
     * 幂等：以 (name_key, tenant_id IS NULL) 为键 updateOrCreate，不受 locale 影响。
     * 需在无租户上下文时调用，以确保 tenant_id 保持为 NULL。
     */
    public function seedDefaultTemplates(): void
    {
        foreach ($this->defaultTemplates() as $template) {
            MailTemplate::withoutGlobalScope('mailTemplateTenant')->updateOrCreate(
                [
                    'name_key' => $template['name_key'],
                    'tenant_id' => null,
                ],
                [
                    'name' => trans('notification.mail_templates.names.' . $template['name_key']),
                    'type' => $template['type'],
                    'subject' => $template['subject'],
                    'html_body' => $template['html_body'],
                    'text_body' => $template['text_body'],
                    'variables' => $template['variables'],
                    'status' => MailTemplate::STATUS_ACTIVATED,
                ]
            );
        }
    }

    /**
     * 预置系统默认模板定义
     *
     * @return array<int, array{name_key: string, type: string, subject: string, html_body: string, text_body: string, variables: array<int, string>}>
     */
    protected function defaultTemplates(): array
    {
        return [
            [
                'name_key' => 'welcome_registration',
                'type' => MailTemplate::TYPE_WELCOME,
                'subject' => '欢迎来到 {{platform_name}}，{{user_name}}！',
                'variables' => ['user_name', 'tenant_name', 'platform_name', 'login_url', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2 style="color:#4f46e5;">{{platform_name}}</h2>
                    <p>{{user_name}}，您好！</p>
                    <p>欢迎来到 {{platform_name}}。您已成功加入 <strong>{{tenant_name}}</strong>，现在可以开始使用了。</p>
                    <p>
                        <a href="{{login_url}}" style="display:inline-block;padding:10px 24px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">立即登录</a>
                    </p>
                    <hr style="border:none;border-top:1px solid #eee;">
                    <p style="color:#999;font-size:12px;">© {{current_year}} {{platform_name}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                {{user_name}}，您好！

                欢迎来到 {{platform_name}}。您已成功加入 {{tenant_name}}，现在可以开始使用了。

                立即登录：{{login_url}}

                © {{current_year}} {{platform_name}}
                TEXT,
            ],
            [
                'name_key' => 'password_reset',
                'type' => MailTemplate::TYPE_RESET,
                'subject' => '重置您的 {{platform_name}} 密码',
                'variables' => ['user_name', 'reset_url', 'platform_name', 'expiry_minutes', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2>重置您的密码</h2>
                    <p>{{user_name}}，您好！</p>
                    <p>我们收到了您的密码重置请求。请点击下方按钮重置密码：</p>
                    <p>
                        <a href="{{reset_url}}" style="display:inline-block;padding:10px 24px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">重置密码</a>
                    </p>
                    <p style="color:#666;font-size:12px;">此链接将在 {{expiry_minutes}} 分钟后过期。如非本人操作，请忽略此邮件。</p>
                    <hr style="border:none;border-top:1px solid #eee;">
                    <p style="color:#999;font-size:12px;">© {{current_year}} {{platform_name}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                {{user_name}}，您好！

                我们收到了您的密码重置请求。请在 {{expiry_minutes}} 分钟内点击以下链接重置密码：

                {{reset_url}}

                如非本人操作，请忽略此邮件。

                © {{current_year}} {{platform_name}}
                TEXT,
            ],
            [
                'name_key' => 'payment_success',
                'type' => MailTemplate::TYPE_BILLING,
                'subject' => '支付成功 - 订单 {{order_no}}',
                'variables' => ['user_name', 'order_no', 'amount', 'currency', 'payment_method', 'tenant_name', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2 style="color:#16a34a;">支付成功</h2>
                    <p>{{user_name}}，您好！</p>
                    <p>您的订单已支付成功，以下是本次支付详情：</p>
                    <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                        <tr><td style="padding:8px;border:1px solid #eee;">订单编号</td><td style="padding:8px;border:1px solid #eee;">{{order_no}}</td></tr>
                        <tr><td style="padding:8px;border:1px solid #eee;">支付金额</td><td style="padding:8px;border:1px solid #eee;">{{currency}} {{amount}}</td></tr>
                        <tr><td style="padding:8px;border:1px solid #eee;">支付方式</td><td style="padding:8px;border:1px solid #eee;">{{payment_method}}</td></tr>
                    </table>
                    <p style="color:#999;font-size:12px;">{{tenant_name}} · © {{current_year}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                {{user_name}}，您好！

                您的订单 {{order_no}} 已支付成功。

                订单编号：{{order_no}}
                支付金额：{{currency}} {{amount}}
                支付方式：{{payment_method}}

                {{tenant_name}} · © {{current_year}}
                TEXT,
            ],
            [
                'name_key' => 'invoice_generated',
                'type' => MailTemplate::TYPE_BILLING,
                'subject' => '账单已生成 - {{invoice_no}}',
                'variables' => ['user_name', 'invoice_no', 'amount', 'currency', 'due_date', 'invoice_url', 'tenant_name', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2>账单已生成</h2>
                    <p>{{user_name}}，您好！</p>
                    <p>您有一张新账单已生成，请及时查看并完成结算：</p>
                    <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                        <tr><td style="padding:8px;border:1px solid #eee;">账单编号</td><td style="padding:8px;border:1px solid #eee;">{{invoice_no}}</td></tr>
                        <tr><td style="padding:8px;border:1px solid #eee;">应付金额</td><td style="padding:8px;border:1px solid #eee;">{{currency}} {{amount}}</td></tr>
                        <tr><td style="padding:8px;border:1px solid #eee;">到期日</td><td style="padding:8px;border:1px solid #eee;">{{due_date}}</td></tr>
                    </table>
                    <p>
                        <a href="{{invoice_url}}" style="display:inline-block;padding:10px 24px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">查看账单</a>
                    </p>
                    <p style="color:#999;font-size:12px;">{{tenant_name}} · © {{current_year}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                {{user_name}}，您好！

                您有一张新账单已生成，请及时查看并完成结算。

                账单编号：{{invoice_no}}
                应付金额：{{currency}} {{amount}}
                到期日：{{due_date}}
                查看账单：{{invoice_url}}

                {{tenant_name}} · © {{current_year}}
                TEXT,
            ],
            [
                'name_key' => 'subscription_expiring',
                'type' => MailTemplate::TYPE_NOTIFICATION,
                'subject' => '您的订阅将在 {{days}} 天后到期',
                'variables' => ['tenant_name', 'plan_name', 'expires_at', 'days', 'renew_url', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2 style="color:#d97706;">订阅即将到期</h2>
                    <p>您好！</p>
                    <p><strong>{{tenant_name}}</strong> 的 <strong>{{plan_name}}</strong> 订阅将在 <strong>{{days}}</strong> 天后到期（{{expires_at}}）。</p>
                    <p>为避免服务中断，请尽快续订：</p>
                    <p>
                        <a href="{{renew_url}}" style="display:inline-block;padding:10px 24px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">立即续订</a>
                    </p>
                    <p style="color:#999;font-size:12px;">© {{current_year}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                您好！

                {{tenant_name}} 的 {{plan_name}} 订阅将在 {{days}} 天后（{{expires_at}}）到期。

                为避免服务中断，请尽快续订：{{renew_url}}

                © {{current_year}}
                TEXT,
            ],
            [
                'name_key' => 'tenant_suspended',
                'type' => MailTemplate::TYPE_NOTIFICATION,
                'subject' => '账户已暂停',
                'variables' => ['tenant_name', 'reason', 'contact_email', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2 style="color:#dc2626;">账户已暂停</h2>
                    <p>您好！</p>
                    <p><strong>{{tenant_name}}</strong> 账户已被暂停。</p>
                    <p><strong>原因：</strong>{{reason}}</p>
                    <p>如有疑问，请联系：<a href="mailto:{{contact_email}}">{{contact_email}}</a></p>
                    <hr style="border:none;border-top:1px solid #eee;">
                    <p style="color:#999;font-size:12px;">© {{current_year}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                您好！

                {{tenant_name}} 账户已被暂停。

                原因：{{reason}}
                如有疑问，请联系：{{contact_email}}

                © {{current_year}}
                TEXT,
            ],
        ];
    }
}
