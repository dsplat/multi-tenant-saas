# Skills 注册指南

本框架提供两个 Skill 用于管理模块拆分和更新：

- **split-push** — 推送模块到最新（框架开发者用）
- **split-pull** — 拉取模块更新（下游项目用）

## 注册方式

根据你使用的 AI Agent 工具，选择对应的注册方式：

---

### Cursor

在项目根目录创建 `.cursorrules` 文件，添加：

```
## Skills

当用户提到"推送模块"、"split push"、"同步模块"时，参考 docs/skills/split-push.md 执行。

当用户提到"拉取模块"、"更新模块"、"split pull"、"安装模块"时，参考 docs/skills/split-pull.md 执行。
```

---

### Windsurf

在项目根目录创建 `.windsurfrules` 文件，添加：

```
## Skills

当用户提到"推送模块"、"split push"、"同步模块"时，参考 docs/skills/split-push.md 执行。

当用户提到"拉取模块"、"更新模块"、"split pull"、"安装模块"时，参考 docs/skills/split-pull.md 执行。
```

---

### GitHub Copilot

在项目根目录创建 `.github/copilot-instructions.md` 文件，添加：

```markdown
## Skills

当用户提到"推送模块"、"split push"、"同步模块"时，参考 docs/skills/split-push.md 执行。

当用户提到"拉取模块"、"更新模块"、"split pull"、"安装模块"时，参考 docs/skills/split-pull.md 执行。
```

---

### Claude Code / MiMoCode

在项目根目录创建 `.claude/skills/split/SKILL.md` 文件，内容复制 `docs/skills/split-push.md` 和 `docs/skills/split-pull.md`。

或在 `.mimocode/skills/` 目录下创建 skill 文件。

---

### Cline

在项目根目录创建 `.clinerules` 文件，添加：

```
## Skills

当用户提到"推送模块"、"split push"、"同步模块"时，参考 docs/skills/split-push.md 执行。

当用户提到"拉取模块"、"更新模块"、"split pull"、"安装模块"时，参考 docs/skills/split-pull.md 执行。
```

---

### Aider

在项目根目录创建 `.aider.conf.yml` 文件，添加：

```yaml
read:
  - docs/skills/split-push.md
  - docs/skills/split-pull.md
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
```

---

## 通用方式

如果以上都不适用，可以直接告诉 AI：

```
请参考 docs/skills/split-push.md 执行模块推送
```

或

```
请参考 docs/skills/split-pull.md 执行模块拉取
```

## 文件位置

```
docs/
└── skills/
    ├── split-push.md    # 推送模块
    ├── split-pull.md    # 拉取模块
    └── REGISTRATION.md  # 本文件
```
