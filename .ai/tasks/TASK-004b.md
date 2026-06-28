# TASK-004b: [Auto-split from TASK-004]

目标: 支付宝 OAuth 模块实现 + SocialiteService 安全加固
只允许修改:
- `src/Services/SocialiteService.php`
- `src/Services/AlipayOAuthService.php`（新建）
禁止: 修改其他文件、新增依赖
预估时间: 3.5 小时
依赖: TASK-004a

说明:
- T2.1: SocialiteService::handleCallback() 捕获 InvalidStateException，abort(403)
- T2.1: SocialiteService::getRedirectUrl() Alipay 走独立流程
- T2.2: SocialiteService::getSupportedProviders() 追加 alipay
- T3.1: 新建 AlipayOAuthService，实现 getAuthorizeUrl/handleCallback/getAccessToken/getUserInfo/sign/verifySign/findOrCreateUser/isConfigured
- T3.2: 完成后在 TASK-004a 中追加 `AlipayOAuthService::class` singleton（或由本子任务完成后由 TASK-004a 最终验收时一并追加）
- 验收: `php -l` 无语法错误，`AlipayOAuthService` 类可被 autoload

---



## 状态
READY
