Now I have a complete picture. Here's my review:

---

## Architecture

TenantMail 的设计合理：继承 `Mailable`，通过 `MailTemplateService` 解耦模板渲染，`TenantContext` 注入租户上下文。`$rendered` 缓存避免 `envelope()` 和 `content()` 的重复查询是好的设计决策。`$attachmentInput` 与父类 `$attachments` 的分离有清晰的注释说明原因，属于合理的变通。

ProcessSubscriptions 中 `TrialService` 用实例方法（`new TrialService()`）而 `DunningService` 用静态方法，风格不一致，但这是已有设计，不是本次引入的问题。`processFailedPayments()` 直接查询 `PaymentOrder` 再委托给 `DunningService`，职责划分清晰。

**评价：合理，模块边界清晰。**

## Code Quality

- 命名规范：`withTenantDefaults`、`resolveTenant`、`resolveBrandColor`、`toAttachment` 命名语义明确
- 注释充分：类文档、方法文档、`$attachmentInput` 的设计原因都有说明
- `toAttachment()` 支持 `path`/`data`/`storage`/`url` 四种模式，覆盖面全
- **不一致**：`content()` 先 `$rendered = $this->renderTemplate()` 再取值，`envelope()` 直接链式调用 `$this->renderTemplate()['subject']`，风格不统一
- lang 文件中英双语同步，placeholder 语法（`:date`, `:price`, `:amount`）符合 Laravel 规范

**评价：整体良好，有一处风格不一致。**

## Type Safety

- 所有属性均有类型声明：`string $templateType`, `array $data`, `?int $tenantId`, `array $attachmentInput`, `?array $rendered`
- 方法返回类型完整：`envelope(): Envelope`, `content(): Content`, `attachments(): array`, `renderTemplate(): ?array`
- `resolveBrandColor(?Tenant $tenant)` 参数可空，实现中正确使用了 `?->` 操作符
- `TenantContext::getTenant()` 返回 `?Tenant`，`resolveTenant()` 返回类型 `?Tenant`，null 传播链完整

**评价：类型标注完整，无遗漏。**

## Security

- 无直接 SQL 拼接，`Tenant::find()` 使用参数化查询
- 附件 `toAttachment()` 中 `fromData` 使用闭包延迟求值（`fn () => $config['data']`），符合 Laravel 安全实践
- `resolveBrandColor` 对 `$branding['brand_color']` 做了空字符串检查并强制 `(string)` 转换
- 无 XSS 风险：模板渲染在 `MailTemplateService` 中完成，变量替换使用 `{{var_name}}` 模式
- 无敏感数据暴露：默认变量仅包含租户名、品牌色、年份、平台名

**评价：无安全问题。**

## Performance

- `renderTemplate()` 缓存结果，避免 `envelope()` + `content()` 两次查询 — 好
- `resolveTenant()` 当 `tenantId` 显式传入时调用 `Tenant::find()`，这是一次额外 DB 查询；但构造时只调用一次，可接受
- `TenantContext::getTenant()` 自带 Request 级缓存，不会重复查询
- `attachments()` 使用 `array_map`，附件数量通常很小，无性能问题
- ProcessSubscriptions 中 `processFailedPayments()` 使用 `distinct()->pluck()` 避免 N+1，`foreach` 内每次调用 `processFailedPayment` 会独立查询，但这是按设计要求逐租户处理催款逻辑

**评价：无性能问题。**

## Potential Bugs

**P1 — `envelope()` 中 `renderTemplate()` 返回 null 时的 PHP 警告**

```php
// 第 56 行
$subject = $this->renderTemplate()['subject']
    ?? ($this->data['platform_name'] ?? config('app.name', 'Notification'));
```

当 `renderTemplate()` 返回 `null` 时（模板不存在），`null['subject']` 在 PHP 8.0+ 会触发 `Warning: Trying to access array offset on value of type null`。虽然 `??` 最终会回退到默认值，但警告会被记录到日志。对比 `content()` 的写法（先存变量再取值），这里应该保持一致：

```php
$rendered = $this->renderTemplate();
$subject = $rendered['subject']
    ?? ($this->data['platform_name'] ?? config('app.name', 'Notification'));
```

**P2 — `toAttachment()` 空路径静默失败**

```php
// 第 172 行
$path = $config['path'] ?? $config[0] ?? '';
$instance = \Illuminate\Mail\Mailables\Attachment::fromPath($path);
```

当附件配置既无 `path` 也无 `data`/`storage`/`url` 时，会以空字符串调用 `fromPath('')`，发送时才会抛异常。建议在 else 分支对空路径做前置校验或抛出 `\InvalidArgumentException`。

**P3 — ProcessSubscriptions `processFailedPayments()` 缺少异常处理**

`DunningService::processFailedPayment()` 和 `suspendTenant()` 内部有事务保护，但如果某个租户处理抛出异常（如 DB 连接中断），整个 foreach 循环会中断，后续租户不会被处理。建议在循环内 try-catch 并 continue。

**评价：有 3 个非阻塞但值得关注的问题。**

## Verdict

**PASS**

P1 是代码质量问题（功能正确但会产生日志噪音），P2/P3 是防御性编程建议。无安全漏洞、无架构缺陷、类型安全完整、满足 TASK-008c 全部交付物要求。

### 【建议改进】（非阻塞）

1. **`envelope()` null 处理**：将 `$this->renderTemplate()['subject']` 拆为两行，与 `content()` 风格一致，避免 PHP 8.x warning
2. **`toAttachment()` 空路径防护**：else 分支对 `$path` 为空时抛出明确异常
3. **`processFailedPayments()` 异常隔离**：在 foreach 内加 try-catch，单租户失败不阻塞其余租户处理
