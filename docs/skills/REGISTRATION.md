# Skills 注册指南

本框架提供以下 Skills 辅助开发：

| Skill | 用途 | 触发词 |
|-------|------|--------|
| split-push | 推送模块到最新 | 推送模块、split push、同步模块 |
| split-pull | 拉取模块更新 | 拉取模块、更新模块、split pull |
| release | 发版流程 | 发版、打 tag、发布新版本 |
| test-fix | 测试修复 | 测试失败、修复测试、phpunit |

## 注册方式

根据你使用的 AI Agent 工具，选择对应的注册方式：

---

### Cursor

在项目根目录创建 `.cursorrules` 文件，添加：

```
## Skills

当用户提到"推送模块"、"split push"、"同步模块"时，参考 docs/skills/split-push.md 执行。

当用户提到"拉取模块"、"更新模块"、"split pull"、"安装模块"时，参考 docs/skills/split-pull.md 执行。

当用户提到"发版"、"打 tag"、"发布新版本"时，参考 docs/skills/release.md 执行。

当用户提到"测试失败"、"修复测试"、"phpunit"时，参考 docs/skills/test-fix.md 执行。
```

---

### Windsurf

在项目根目录创建 `.windsurfrules` 文件，添加：

```
## Skills

当用户提到"推送模块"、"split push"、"同步模块"时，参考 docs/skills/split-push.md 执行。

当用户提到"拉取模块"、"更新模块"、"split pull"、"安装模块"时，参考 docs/skills/split-pull.md 执行。

当用户提到"发版"、"打 tag"、"发布新版本"时，参考 docs/skills/release.md 执行。

当用户提到"测试失败"、"修复测试"、"phpunit"时，参考 docs/skills/test-fix.md 执行。
```

---

### GitHub Copilot

在项目根目录创建 `.github/copilot-instructions.md` 文件，添加：

```markdown
## Skills

当用户提到"推送模块"、"split push"、"同步模块"时，参考 docs/skills/split-push.md 执行。

当用户提到"拉取模块"、"更新模块"、"split pull"、"安装模块"时，参考 docs/skills/split-pull.md 执行。

当用户提到"发版"、"打 tag"、"发布新版本"时，参考 docs/skills/release.md 执行。

当用户提到"测试失败"、"修复测试"、"phpunit"时，参考 docs/skills/test-fix.md 执行。
```

---

### Claude Code / MiMoCode

在项目根目录创建 `.claude/skills/` 目录，将 `docs/skills/` 下的 .md 文件复制过去。

或在 `.mimocode/skills/` 目录下创建 skill 文件。

---

### Cline

在项目根目录创建 `.clinerules` 文件，添加：

```
## Skills

当用户提到"推送模块"、"split push"、"同步模块"时，参考 docs/skills/split-push.md 执行。

当用户提到"拉取模块"、"更新模块"、"split pull"、"安装模块"时，参考 docs/skills/split-pull.md 执行。

当用户提到"发版"、"打 tag"、"发布新版本"时，参考 docs/skills/release.md 执行。

当用户提到"测试失败"、"修复测试"、"phpunit"时，参考 docs/skills/test-fix.md 执行。
```

---

### Aider

在项目根目录创建 `.aider.conf.yml` 文件，添加：

```yaml
read:
  - docs/skills/split-push.md
  - docs/skills/split-pull.md
  - docs/skills/release.md
  - docs/skills/test-fix.md
```

---

### Continue

在 `.continue/config.yml` 中添加：

```yaml
customCommands:
  - name: split-push
    description: 推送模块到最新
    prompt: "参考 docs/skills/split-push.md 执行模块推送"
  - name: split-pull
    description: 拉取模块更新
    prompt: "参考 docs/skills/split-pull.md 执行模块拉取"
  - name: release
    description: 发版
    prompt: "参考 docs/skills/release.md 执行发版流程"
  - name: test-fix
    description: 修复测试
    prompt: "参考 docs/skills/test-fix.md 执行测试修复"
```

---

## 通用方式

如果以上都不适用，可以直接告诉 AI：

```
请参考 docs/skills/xxx.md 执行 xxx
```

## 文件位置

```
docs/
└── skills/
    ├── split-push.md    # 推送模块
    ├── split-pull.md    # 拉取模块
    ├── release.md       # 发版流程
    ├── test-fix.md      # 测试修复
    └── REGISTRATION.md  # 本文件
```
