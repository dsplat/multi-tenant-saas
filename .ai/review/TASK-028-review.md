## Architecture

模块边界清晰，职责划分合理。`TenantKeyService` 和 `BrandingService` 各自独立，分别负责加密密钥和白标定制。两个 Model 都正确使用了 `HasGlobalId` + `BelongsToTenant` trait 组合，与现有代码库模式一致。

Service 层显式绕过 `TenantScope` 并通过 `tenant_id` 参数操作的设计合理——因为这些是管理级操作，调用方需要自行保证权限。`config/tenancy.php` 的配置划分（`encryption` / `branding` 两个独立区块）结构清晰。

信封加密架构（系统主密钥加密租户密钥）是业界标准做法，密钥轮换的 transaction + async re-encrypt 设计合理。

## Code Quality

命名规范整体遵循 PSR-12，PHPDoc 注释完整且使用中文，与项目风格一致。方法命名语义明确（`generateKey`、`rotateKey`、`importByok`、`resolveDomain` 等）。

代码复用做得好：`encryptWithMasterKey` / `decryptWithMasterKey` 与 `encryptWithKey` / `decryptWithKey` 分离了信封加密和数据加密两层；`getConfig()` 的 "不存在则创建默认" 模式避免了调用方的空值检查。

`normalizeByokKey()` 的三段式解码逻辑（raw → base64 → hex）清晰可读。

小问题：`renderEmailTemplate` 的 `$data` 参数声明了但未使用（`BrandingService.php:192`）。

## Type Safety

方法参数和返回值类型声明完整，PHPDoc 的 `@param` / `@return` 标注齐全。`casts()` 方法使用了 PHP 8.1+ 的返回值声明风格，与项目规范一致。

`reEncryptData` 的 `@param` 使用了精确的 shape 类型标注 `array{table: string, column: string, ...}`，非常好。

不足之处：`key_type` 和 `status` 使用原始字符串（`'system'` / `'byok'`、`'active'` / `'retired'` / `'rotating'`），没有使用 PHP 8.1 enum。虽然与项目现有模式一致（项目中其他 status 列也是原始字符串），但从类型安全角度仍有改进空间。migration 定义了 `'rotating'` 状态但代码中从未使用。

## Security

**信封加密实现正确**：`random_bytes()` 生成 IV，`OPENSSL_RAW_DATA` 模式，`base64_encode(iv + ciphertext)` 拼接——这些都是正确的做法。`getMasterKey()` 用 `hash('sha256', ..., true)` 派生 32 字节密钥也合理。

**XSS 风险**：`renderEmailTemplate()` 中 `$content` 参数直接拼入 HTML（`BrandingService.php:205`），未经过 `e()` 转义。如果调用方传入用户可控内容，存在 XSS 风险。同时 `custom_css` 字段无任何过滤，若在页面 `<style>` 标签中直接注入，攻击者可通过 CSS exfiltration 窃取数据。

**敏感数据暴露**：`TenantKey` 模型的 `$fillable` 包含 `encrypted_key`，虽非明文密钥但仍属敏感信息。模型未设置 `$hidden` 属性，序列化时（如 API 响应）可能泄露。`BrandinConfig` 的 `$fillable` 包含 `tenant_id`，`updateConfig()` 接受任意 `$data` 数组直接 `fill()`，若调用方传入不受信数据，可能被利用修改 `tenant_id` 实现跨租户篡改。

**域名校验**：`isValidDomain()` 正则 `/^([a-z0-9-]+\.)+[a-z]{2,}$/i` 合理，阻止了 IDN 和 IP 地址。但自定义域名绑定后，若有其他服务基于此域名路由请求，需确保 DNS 验证机制，当前无此逻辑。

## Performance

**N+1 问题**：`reEncryptData()` 对每个 field 执行 `get()` 加载全部行到内存，然后逐行 `update()`。对于大数据量表，这是 O(N) 内存 + N 次单行 UPDATE。应使用 chunked 查询和批量更新。

**主密钥重复派生**：`getMasterKey()` 每次调用都执行 `hash('sha256', ...)`。在 `reEncryptData` 循环中，`encryptWithKey` 和 `decryptWithKey` 会频繁触发，但由于主密钥只在 `encryptWithMasterKey` / `decryptWithMasterKey` 中使用（而非在数据加密/解密中），实际影响有限。

**`getConfig()` 的 `fresh()` 调用**：`updateConfig()` 中 `$config->fill($data)->save()` 后调用 `$config->fresh()` 会额外执行一次 SELECT。鉴于 `fill() + save()` 已同步更新模型属性，`fresh()` 是不必要的。

## Potential Bugs

**1. `getConfig()` 竞态条件**：两个并发请求对同一 `tenantId` 调用 `getConfig()`，都发现记录不存在，然后都执行 `create()`。由于 `branding_configs` 表有 `unique('tenant_id')` 约束，第二次 `create()` 会抛出唯一约束违反异常，且该异常未被捕获。应加 `try/catch` 或使用 `firstOrCreate()`。

**2. `rotateKey()` 中间状态风险**：事务内先 retire 旧密钥再创建新密钥，如果 `createKeyRecord` 中 `encryptWithMasterKey` 失败（如主密钥配置丢失），事务回滚，旧密钥恢复 active——这是正确的。但 `dispatchReEncrypt` 在事务提交后执行，如果队列 job 内部 `findKey` 返回 null（密钥记录被并发删除），re-encrypt 静默跳过，数据仍用旧密钥加密，无任何告警。

**3. AES-256-CBC 无认证**：使用 CBC 模式没有 HMAC 认证，存在 padding oracle 攻击风险。对于"加密密钥管理"这个敏感场景，应使用 `aes-256-gcm` 或在 CBC 基础上增加 HMAC 验证。虽然当前场景是内部系统、非用户直接提交密文，但作为加密基础设施，这是一个架构级隐患。

**4. `custom_domain` 的唯一约束与 soft delete 冲突**：`branding_configs` 表有 `softDeletes()` 和 `unique('custom_domain')`。如果租户 A 绑定了 `app.example.com` 后被软删除，该域名的唯一约束仍存在，其他租户无法绑定同一域名——除非手动清理。

**5. `renderEmailTemplate` 未使用 `$data` 参数**：方法签名声明了 `array $data = []` 但方法体完全未使用，调用方可能误以为可以传入模板变量。

## Verdict

**PASS**（附带建议改进）

整体代码质量高，架构合理，安全基线到位。无阻塞性缺陷。

### 【建议改进】

1. **`getConfig()` 竞态防护**：`BrandingService.php:39` 的 `create()` 应包裹 `try/catch` 捕获唯一约束违反，或改用 `firstOrCreate`，避免并发场景下的未处理异常。

2. **AES-CBC → AES-GCM**：`TenantKeyService.php` 的 `cipher()` 方法默认返回 `aes-256-cbc`，建议改为 `aes-256-gcm` 以获得认证加密，防止 padding oracle 攻击。GCM 模式下 `openssl_encrypt` / `openssl_decrypt` 需要额外处理 tag。

3. **`updateConfig` 的 `$data` 白名单过滤**：`BrandingService.php:57` 的 `$config->fill($data)` 直接接受外部数组，建议过滤为 `$fillable` 子集或仅允许预期字段，防止 `tenant_id` 被恶意修改。

4. **`reEncryptData` 分批处理**：`TenantKeyService.php:197` 的 `$query->get()` 应改为 `chunk(500, ...)` 避免大表内存溢出。

5. **队列 job 静默失败增加日志**：`TenantKeyService.php:423` 的 `if ($old !== null && $new !== null)` 分支外应增加 `Log::warning` 记录密钥查找失败的情况。

6. **`renderEmailTemplate` 清理未用参数**：`BrandingService.php:192` 的 `$data` 参数要么实现模板变量替换，要么移除，避免误导调用方。

7. **`custom_domain` 唯一约束与 soft delete**：考虑在软删除时将 `custom_domain` 置为 NULL，或改用条件唯一索引，避免域名被"钉死"。

8. **`TenantKey` 设置 `$hidden`**：建议将 `encrypted_key` 加入 `$hidden` 数组，防止模型意外序列化时泄露。
