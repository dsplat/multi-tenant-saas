<?php

namespace MultiTenantSaas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Notification\Services\MailTemplateService;

/**
 * 基于数据库模板渲染的通用租户邮件
 *
 * 通过 MailTemplateService 从 mail_templates 表读取模板（支持租户覆盖与系统默认回退），
 * 自动注入租户上下文默认变量，并以 HTML/纯文本双格式发送，支持附件。
 *
 * 注：父类 Illuminate\Mail\Mailable 已声明 public $attachments 作为附件累积缓冲区，
 * 子类无法以 array 类型重新声明；故构造函数接收的附件输入存放于本类 $attachmentInput，
 * 由 attachments() 方法归一化为 Attachment 实例后交由父类 hydrate。
 */
class TenantMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $templateType;

    public array $data;

    public ?int $tenantId;

    public $locale;

    /**
     * 构造函数接收的原始附件输入（字符串路径 / 数组配置 / Attachment 实例）。
     */
    protected array $attachmentInput;

    /**
     * 渲染缓存，避免 envelope()/content() 重复查询模板。
     */
    protected ?array $rendered = null;

    public function __construct(
        string $templateType,
        array $data = [],
        ?int $tenantId = null,
        array $attachments = [],
        ?string $locale = null,
    ) {
        $this->templateType = $templateType;
        $this->tenantId = $tenantId;
        $this->locale = $locale;
        $this->attachmentInput = $attachments;
        $this->data = $this->withTenantDefaults($data);
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderTemplate()['subject']
            ?? ($this->data['platform_name'] ?? config('app.name', 'Notification'));

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $rendered = $this->renderTemplate();
        $html = $rendered['html'] ?? '';
        $text = $rendered['text'] ?? '';

        // 将模板的纯文本正文作为 multipart/alternative 的 text 分支写入 Symfony 邮件，
        // 与 htmlString 渲染的 HTML 分支共同构成 HTML/纯文本双格式。
        if ($text !== '') {
            $this->withSymfonyMessage(function ($message) use ($text) {
                $message->text($text);
            });
        }

        return new Content(
            htmlString: $html,
        );
    }

    public function attachments(): array
    {
        return array_map(function ($attachment) {
            return is_array($attachment) ? $this->toAttachment($attachment) : $attachment;
        }, $this->attachmentInput);
    }

    /**
     * 调用 MailTemplateService::render() 渲染模板，并对结果做单例缓存。
     */
    protected function renderTemplate(): ?array
    {
        if ($this->rendered === null) {
            $this->rendered = app(MailTemplateService::class)
                ->render($this->templateType, $this->data, $this->tenantId, $this->locale);
        }

        return $this->rendered;
    }

    /**
     * 从 TenantContext 获取租户名称、品牌色等，合并为默认变量。
     * 用户传入的 $data 优先于默认值。
     */
    protected function withTenantDefaults(array $data): array
    {
        $tenant = $this->resolveTenant();
        $platformName = (string) config('tenancy.mail_templates.default_from_name', config('app.name', 'Platform'));

        $defaults = [
            'tenant_name' => $tenant?->name ?? $platformName,
            'tenant_brand_color' => $this->resolveBrandColor($tenant),
            'current_year' => now()->year,
            'platform_name' => $platformName,
        ];

        return array_merge($defaults, $data);
    }

    /**
     * 解析当前租户：显式 tenantId 优先，否则回退到 TenantContext。
     */
    protected function resolveTenant(): ?Tenant
    {
        if ($this->tenantId !== null) {
            return Tenant::find($this->tenantId);
        }

        return TenantContext::getTenant();
    }

    /**
     * 读取租户品牌色（branding.brand_color），缺失时回退到框架默认主色。
     */
    protected function resolveBrandColor(?Tenant $tenant): string
    {
        $branding = $tenant?->branding;

        if (is_array($branding) && isset($branding['brand_color']) && $branding['brand_color'] !== '') {
            return (string) $branding['brand_color'];
        }

        return '#4f46e5';
    }

    /**
     * 将数组形式的附件配置转换为 Attachment 实例。
     *
     * 支持的键:
     *  - path:      本地/远程路径附件
     *  - data+name: 内存数据附件（name 必填）
     *  - storage:   存储磁盘附件（可配合 disk 指定磁盘）
     *  - url:       http(s) URL 附件
     *  - name/mime: 可选，用于覆盖文件名与 MIME 类型
     */
    protected function toAttachment(array $config): Attachment
    {
        if (isset($config['data'])) {
            $instance = Attachment::fromData(
                fn () => $config['data'],
                $config['name'] ?? null,
            );
        } elseif (isset($config['storage'])) {
            $instance = isset($config['disk'])
                ? Attachment::fromStorageDisk($config['disk'], $config['storage'])
                : Attachment::fromStorage($config['storage']);
        } elseif (isset($config['url'])) {
            $instance = Attachment::fromUrl($config['url']);
        } else {
            $path = $config['path'] ?? $config[0] ?? '';
            $instance = Attachment::fromPath($path);
        }

        if (isset($config['name'])) {
            $instance->as($config['name']);
        }

        if (isset($config['mime'])) {
            $instance->withMime($config['mime']);
        }

        return $instance;
    }
}
