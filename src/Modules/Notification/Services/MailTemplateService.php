<?php

namespace MultiTenantSaas\Modules\Notification\Services;

use Illuminate\Database\Eloquent\Builder;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Notification\Models\MailTemplate;

/**
 * 邮件模板服务
 *
 * 负责邮件模板的 CRUD、变量替换、三级覆盖与系统默认回退、以及预置系统模板播种。
 *
 * 三级覆盖优先级：
 * - 租户自定义模板: tenant_id = 指定租户, scope = tenant
 * - 项目定制模板: tenant_id IS NULL, scope = project
 * - 系统默认模板: tenant_id IS NULL, scope = system
 *
 * findTemplate/render 显式接收 tenantId，绕过全局作用域以支持跨租户查找。
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
     * 查找模板：三级优先级链
     *
     * 优先级：tenant(locale) > tenant(default) > project(locale) > project(default) > system(locale) > system(default)
     *
     * 绕过 mailTemplateTenant 全局作用域，以支持显式指定租户的跨租户查找。
     */
    public function findTemplate(string $type, ?int $tenantId = null, ?string $locale = null): ?MailTemplate
    {
        $contextId = TenantContext::getId();
        $tenantId = $tenantId ?? ($contextId !== null ? (int) $contextId : null);

        $baseQuery = function () use ($type): Builder {
            return MailTemplate::withoutGlobalScope('mailTemplateTenant')
                ->where('type', $type)
                ->where('status', MailTemplate::STATUS_ACTIVATED);
        };

        // 1. 租户定制（指定 locale）
        if ($tenantId !== null && $locale !== null) {
            $template = $baseQuery()
                ->where('tenant_id', $tenantId)
                ->where('scope', MailTemplate::SCOPE_TENANT)
                ->where('locale', $locale)
                ->first();

            if ($template) {
                return $template;
            }
        }

        // 2. 租户定制（默认语言）
        if ($tenantId !== null) {
            $template = $baseQuery()
                ->where('tenant_id', $tenantId)
                ->where('scope', MailTemplate::SCOPE_TENANT)
                ->where(function ($q) {
                    $q->whereNull('locale')->orWhere('locale', 'zh_CN');
                })
                ->first();

            if ($template) {
                return $template;
            }
        }

        // 3. 项目定制（指定 locale）
        if ($locale !== null) {
            $template = $baseQuery()
                ->whereNull('tenant_id')
                ->where('scope', MailTemplate::SCOPE_PROJECT)
                ->where('locale', $locale)
                ->first();

            if ($template) {
                return $template;
            }
        }

        // 4. 项目定制（默认语言）
        $template = $baseQuery()
            ->whereNull('tenant_id')
            ->where('scope', MailTemplate::SCOPE_PROJECT)
            ->where(function ($q) {
                $q->whereNull('locale')->orWhere('locale', 'zh_CN');
            })
            ->first();

        if ($template) {
            return $template;
        }

        // 5. 系统默认（指定 locale）
        if ($locale !== null) {
            $template = $baseQuery()
                ->whereNull('tenant_id')
                ->where('scope', MailTemplate::SCOPE_SYSTEM)
                ->where('locale', $locale)
                ->first();

            if ($template) {
                return $template;
            }
        }

        // 6. 系统默认（兜底）
        return $baseQuery()
            ->whereNull('tenant_id')
            ->where(function ($q) {
                $q->where('scope', MailTemplate::SCOPE_SYSTEM)
                    ->orWhereNull('scope'); // 兼容老数据
            })
            ->where(function ($q) {
                $q->whereNull('locale')->orWhere('locale', 'zh_CN');
            })
            ->first();
    }

    /**
     * 查找模板并执行变量替换
     *
     * @return array{subject: string, html: string, text: string}|null
     */
    public function render(string $type, array $data, ?int $tenantId = null, ?string $locale = null): ?array
    {
        $template = $this->findTemplate($type, $tenantId, $locale);

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
     * 播种系统默认模板（tenant_id = null, scope = system）
     *
     * 幂等：以 (name_key, tenant_id IS NULL, scope) 为键 updateOrCreate，不受 locale 影响。
     * 需在无租户上下文时调用，以确保 tenant_id 保持为 NULL。
     */
    public function seedDefaultTemplates(): void
    {
        foreach ($this->defaultTemplates() as $template) {
            MailTemplate::withoutGlobalScope('mailTemplateTenant')->updateOrCreate(
                [
                    'name_key' => $template['name_key'],
                    'tenant_id' => null,
                    'scope' => MailTemplate::SCOPE_SYSTEM,
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
            [
                'name_key' => 'operator_registration',
                'type' => MailTemplate::TYPE_REGISTRATION,
                'subject' => '欢迎注册 {{platform_name}}，请验证邮箱',
                'variables' => ['name', 'verification_url', 'platform_name', 'expiry_hours', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2 style="color:#4f46e5;">{{platform_name}}</h2>
                    <p>{{name}}，您好！</p>
                    <p>感谢您注册 {{platform_name}}。请点击下方按钮验证您的邮箱：</p>
                    <p>
                        <a href="{{verification_url}}" style="display:inline-block;padding:10px 24px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">验证邮箱</a>
                    </p>
                    <p style="color:#666;font-size:12px;">此链接将在 {{expiry_hours}} 小时后过期。如非本人操作，请忽略此邮件。</p>
                    <hr style="border:none;border-top:1px solid #eee;">
                    <p style="color:#999;font-size:12px;">© {{current_year}} {{platform_name}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                {{name}}，您好！

                感谢您注册 {{platform_name}}。请在 {{expiry_hours}} 小时内点击以下链接验证邮箱：

                {{verification_url}}

                如非本人操作，请忽略此邮件。

                © {{current_year}} {{platform_name}}
                TEXT,
            ],
            [
                'name_key' => 'operator_verification',
                'type' => MailTemplate::TYPE_VERIFICATION,
                'subject' => '邮箱验证 - {{platform_name}}',
                'variables' => ['name', 'verification_url', 'platform_name', 'expiry_hours', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2>邮箱验证</h2>
                    <p>{{name}}，您好！</p>
                    <p>请点击下方按钮验证您的邮箱地址：</p>
                    <p>
                        <a href="{{verification_url}}" style="display:inline-block;padding:10px 24px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">验证邮箱</a>
                    </p>
                    <p style="color:#666;font-size:12px;">此链接将在 {{expiry_hours}} 小时后过期。</p>
                    <hr style="border:none;border-top:1px solid #eee;">
                    <p style="color:#999;font-size:12px;">© {{current_year}} {{platform_name}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                {{name}}，您好！

                请在 {{expiry_hours}} 小时内点击以下链接验证邮箱：

                {{verification_url}}

                © {{current_year}} {{platform_name}}
                TEXT,
            ],
            [
                'name_key' => 'tenant_application_submitted',
                'type' => MailTemplate::TYPE_APPLICATION_SUBMITTED,
                'subject' => '租户申请已提交 - {{application_code}}',
                'variables' => ['name', 'org_name', 'application_code', 'status_url', 'platform_name', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2 style="color:#4f46e5;">租户申请已提交</h2>
                    <p>{{name}}，您好！</p>
                    <p>您的租户申请已提交，以下是申请详情：</p>
                    <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                        <tr><td style="padding:8px;border:1px solid #eee;">组织名称</td><td style="padding:8px;border:1px solid #eee;">{{org_name}}</td></tr>
                        <tr><td style="padding:8px;border:1px solid #eee;">申请编号</td><td style="padding:8px;border:1px solid #eee;">{{application_code}}</td></tr>
                        <tr><td style="padding:8px;border:1px solid #eee;">当前状态</td><td style="padding:8px;border:1px solid #eee;">审核中</td></tr>
                    </table>
                    <p>
                        <a href="{{status_url}}" style="display:inline-block;padding:10px 24px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">查看申请进度</a>
                    </p>
                    <p style="color:#999;font-size:12px;">审核通常需要 1-3 个工作日。</p>
                    <hr style="border:none;border-top:1px solid #eee;">
                    <p style="color:#999;font-size:12px;">© {{current_year}} {{platform_name}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                {{name}}，您好！

                您的租户申请已提交。

                组织名称：{{org_name}}
                申请编号：{{application_code}}
                当前状态：审核中

                查看进度：{{status_url}}

                审核通常需要 1-3 个工作日。

                © {{current_year}} {{platform_name}}
                TEXT,
            ],
            [
                'name_key' => 'tenant_application_approved',
                'type' => MailTemplate::TYPE_APPLICATION_APPROVED,
                'subject' => '租户申请已通过 - {{org_name}}',
                'variables' => ['name', 'org_name', 'application_code', 'console_url', 'platform_name', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2 style="color:#16a34a;">申请已通过</h2>
                    <p>{{name}}，您好！</p>
                    <p>您的租户申请（<strong>{{org_name}}</strong>）已通过审核。现在可以登录控制台开始使用了。</p>
                    <p>
                        <a href="{{console_url}}" style="display:inline-block;padding:10px 24px;background:#16a34a;color:#fff;text-decoration:none;border-radius:5px;">进入控制台</a>
                    </p>
                    <hr style="border:none;border-top:1px solid #eee;">
                    <p style="color:#999;font-size:12px;">© {{current_year}} {{platform_name}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                {{name}}，您好！

                您的租户申请（{{org_name}}）已通过审核。

                进入控制台：{{console_url}}

                © {{current_year}} {{platform_name}}
                TEXT,
            ],
            [
                'name_key' => 'tenant_application_rejected',
                'type' => MailTemplate::TYPE_APPLICATION_REJECTED,
                'subject' => '租户申请未通过 - {{org_name}}',
                'variables' => ['name', 'org_name', 'application_code', 'reject_reason', 'contact_email', 'platform_name', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2 style="color:#dc2626;">申请未通过</h2>
                    <p>{{name}}，您好！</p>
                    <p>很抱歉，您的租户申请（<strong>{{org_name}}</strong>）未通过审核。</p>
                    <p><strong>原因：</strong>{{reject_reason}}</p>
                    <p>如有疑问，请联系：<a href="mailto:{{contact_email}}">{{contact_email}}</a></p>
                    <p>您可以在解决问题后重新提交申请。</p>
                    <hr style="border:none;border-top:1px solid #eee;">
                    <p style="color:#999;font-size:12px;">© {{current_year}} {{platform_name}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                {{name}}，您好！

                很抱歉，您的租户申请（{{org_name}}）未通过审核。

                原因：{{reject_reason}}

                如有疑问，请联系：{{contact_email}}
                您可以在解决问题后重新提交申请。

                © {{current_year}} {{platform_name}}
                TEXT,
            ],
            [
                'name_key' => 'operator_invitation',
                'type' => MailTemplate::TYPE_INVITATION,
                'subject' => '您被邀请加入 {{org_name}} - {{platform_name}}',
                'variables' => ['name', 'inviter_name', 'org_name', 'invite_url', 'platform_name', 'expiry_days', 'current_year'],
                'html_body' => <<<'HTML'
                <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
                    <h2 style="color:#4f46e5;">加入邀请</h2>
                    <p>{{name}}，您好！</p>
                    <p><strong>{{inviter_name}}</strong> 邀请您加入 <strong>{{org_name}}</strong>。</p>
                    <p>
                        <a href="{{invite_url}}" style="display:inline-block;padding:10px 24px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">接受邀请</a>
                    </p>
                    <p style="color:#666;font-size:12px;">此链接将在 {{expiry_days}} 天后过期。</p>
                    <hr style="border:none;border-top:1px solid #eee;">
                    <p style="color:#999;font-size:12px;">© {{current_year}} {{platform_name}}</p>
                </div>
                HTML,
                'text_body' => <<<'TEXT'
                {{name}}，您好！

                {{inviter_name}} 邀请您加入 {{org_name}}。

                接受邀请：{{invite_url}}

                此链接将在 {{expiry_days}} 天后过期。

                © {{current_year}} {{platform_name}}
                TEXT,
            ],
        ];
    }
}
