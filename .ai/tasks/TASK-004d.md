# TASK-004d: [Auto-split from TASK-004]

**目标:** i18n 完整性扫描 + AlipayOAuth 测试 + 全量回归验证
**只允许修改:**
- `tests/CoreServicesTest.php`
**禁止:** 修改其他文件
**预估时间:** 1 小时
**依赖:** TASK-004b, TASK-004c（需 AlipayOAuthService 和 i18n 翻译就绪）

**具体内容：**
1. 添加 AlipayOAuth 测试：
   - `test_alipay_service_can_be_resolved` — `app(AlipayOAuthService::class)` 返回实例
   - `test_alipay_is_configured_returns_false_when_not_set` — 未配置时返回 false
   - `test_alipay_is_in_supported_providers` — `getSupportedProviders()` 包含 alipay
2. i18n 完整性扫描：`grep -rohP "trans\(['\"]([^'\"]+)" src/` 检查所有 trans() key 在 lang 文件中存在
3. 全量回归：`php vendor/bin/phpunit` 全绿（0 失败）

**验收：** 全部测试通过；所有 trans() key 在 zh_CN/en 中均存在；语法检查无错误


## 状态
READY
