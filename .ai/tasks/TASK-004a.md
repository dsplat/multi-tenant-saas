# TASK-004a: [Auto-split from TASK-004]

目标: 测试基座加固 — 注册全部 17 个新服务 singleton + 全量回归修复测试通过
只允许修改:
- `src/TenancyServiceProvider.php`
- `tests/TestCase.php`
禁止: 修改其他文件、新增依赖
预估时间: 1.5 小时
依赖: 无

说明:
- T1.2: 在 register() 末尾追加 17 个 singleton（UserProfileService ~ SocialiteService）
- T1.3/T1.4: 运行全量测试，修复因服务未注册导致的失败（如构造函数依赖绑定）
- TestCase.php 表已存在（35张），仅在需要时微调 schema 兼容性
- 验收: `php vendor/bin/phpunit` 全绿

---



## 状态
READY
