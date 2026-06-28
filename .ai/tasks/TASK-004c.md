# TASK-004c: [Auto-split from TASK-004]

目标: i18n 翻译补全 + Alipay 测试 + 最终全量验收
只允许修改:
- `lang/zh_CN/common.php`
- `lang/en/common.php`
- `tests/AlipayOAuthTest.php`（新建）
- `tests/CoreServicesTest.php`
禁止: 修改其他文件、新增依赖
预估时间: 1 小时
依赖: TASK-004a, TASK-004b

说明:
- T3.2: 补充 `alipay` 翻译 key（中英文），确认 `oauth_state_invalid` 已存在
- T4.1/T4.2: 扫描全部 trans() 调用，补全缺失翻译
- T3.3: 新建 AlipayOAuthTest，测试服务解析 + isConfigured 返回 false + alipay 在提供商列表中
- 最终验收: `php vendor/bin/phpunit` 全绿，18 个 singleton 注册完整，6 个提供商含 alipay
- 注: TASK-004b 完成后需在 TenancyServiceProvider 追加 AlipayOAuthService singleton（可在本子任务中由编辑 TASK-004a 产出的文件完成，或提前在 TASK-004b 中一并处理）

---

**补充说明:** TASK-004b 与 TASK-004c 之间存在一个 TenancyServiceProvider 的追加注册点（AlipayOAuthService singleton）。建议在 TASK-004b 完成时直接编辑 TenancyServiceProvider.php 追加此行，或在 TASK-004c 验收阶段一并处理。若选择后者，TASK-004c 的文件列表需追加 `src/TenancyServiceProvider.php`，但会与 TASK-004a 重叠。**推荐方案: TASK-004b 中直接编辑 TenancyServiceProvider.php 追加 AlipayOAuthService 注册**（该文件已在 TASK-004a 中被修改，但追加一行不属于冲突——TASK-004a 负责 17 个注册，TASK-004b 负责追加第 18 个）。此时文件列表调整为：

- TASK-004b: `src/Services/SocialiteService.php`, `src/Services/AlipayOAuthService.php`, `src/TenancyServiceProvider.php`（仅追加 1 行 singleton 注册）
- TASK-004a: `tests/TestCase.php`（仅测试基座）
- TASK-004c: `lang/zh_CN/common.php`, `lang/en/common.php`, `tests/AlipayOAuthTest.php`, `tests/CoreServicesTest.php`

冲突自检更新: 无重叠 ✓


## 状态
READY
