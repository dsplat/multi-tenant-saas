# Sprint-003: v0.5.0 AI 基座 (AI Foundation)

**周期：** 2026-07-01 至 2026-08-15  
**状态：** PENDING  
**目标：** 预植入 AI 模块基础设施，包括统一模型网关、文本/图片/视频 AI 能力、知识库(RAG)、租户级 AI 配置与用量管理、AI API 层

---

## 任务列表

| 任务ID | 标题 | 状态 | 依赖 | 并行波次 |
|--------|------|------|------|----------|
| TASK-010 | AI 模型网关 | READY | 无 | Wave 1 |
| TASK-011 | 文本 AI 服务 | READY | TASK-010 | Wave 2 |
| TASK-012 | 图片 AI 服务 | READY | TASK-010 | Wave 2 |
| TASK-013 | 视频 AI 服务 | READY | TASK-010 | Wave 2 |
| TASK-014b | 知识库模块（RAG） | READY | TASK-010, TASK-011 | Wave 3 |
| TASK-014 | 租户 AI 配置与用量管理 | READY | TASK-010~013, TASK-007⚠ | Wave 3 |
| TASK-014c | AI API 层 | READY | TASK-010~014b | Wave 4 |

> **⚠ 跨版本依赖**: TASK-014 依赖 v0.4.0 的 TASK-007 (UsageService)。如果 Sprint-002 的 TASK-007 未通过，本任务将被阻塞。

---

## 并行执行计划

```
Wave 1:  TASK-010
              │
Wave 2:  TASK-011 ── TASK-012 ── TASK-013  (串行，共享 config/ai.php + lang/ai.php)
              │
Wave 3:  TASK-014b ── TASK-014  (串行，共享 config/ai.php + lang/ai.php)
              │
Wave 4:  TASK-014c
```

> **⚠ 文件共享警告**: TASK-011、TASK-012、TASK-013、TASK-014b、TASK-014 均追加修改 `config/ai.php` 和 `lang/zh_CN/ai.php`、`lang/en/ai.php`。**必须串行执行，不可并行**。

```bash
# Wave 1: TASK-010 无依赖
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-010

# Wave 2: TASK-011~013 串行执行（共享 config/ai.php + lang/ai.php）
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-011
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-012
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-013

# Wave 3: TASK-014b 和 TASK-014 串行（共享 config/ai.php + lang/ai.php）
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-014b
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-014

# Wave 4: TASK-014c 依赖所有前置
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-014c
```

---

## Sprint 目标

1. **AI 模型网关**: 统一网关屏蔽多提供商差异，支持 OpenAI/智谱/DALL-E/Stability/Runway/Kling
2. **文本 AI**: 聊天补全、流式输出、嵌入向量、提示词模板管理
3. **图片/视频 AI**: 文生图、图生图、文生视频、异步任务轮询
4. **知识库 RAG**: 文档导入、分块嵌入、向量检索、端到端 RAG 问答
5. **租户 AI 管理**: 能力开关、API Key 配置、用量追踪、配额管理、计费集成
6. **AI API 层**: RESTful 端点暴露全部 AI 能力

## 成功标准

- 端到端流程: 配置 AI 提供商 → 开启租户 AI 能力 → 文本对话/流式输出 → 文生图 → 文生视频 → 创建知识库 → 上传文档 → RAG 问答 → 用量追踪 → 配额检查 → 超额告警 → 费用记录 → API 端点可用
- 数据库新增 ~9 张表（ai_providers, ai_requests, ai_model_aliases, ai_prompts, ai_tenant_configs, ai_usage_quotas, knowledge_bases, knowledge_documents, knowledge_chunks）
- 全量测试通过（预计 ~670 测试）

---

## 关键风险

1. **跨版本依赖**: TASK-014 依赖 TASK-007 (UsageService)，需 Sprint-002 先完成
2. **文件冲突**: 5 个任务共享 `config/ai.php` 和 `lang/ai.php`，强制串行
3. **composer 依赖**: TASK-014b 需新增 `smalot/pdfparser`、`phpoffice/phpword`、`league/html-to-markdown`
4. **向量存储**: MySQL JSON 方案仅供开发，生产环境需 Redis Stack 或外部向量数据库

---

## 相关文档

- [完整功能规划](../../../Library/Application%20Support/Qoder/SharedClientCache/cache/plans/SaaS框架完整功能规划_task-064.md)
- [TASK-010](../tasks/TASK-010.md) ~ [TASK-014c](../tasks/TASK-014c.md)
