# 系统小秘书（第 0 号数字员工）设计

## 目标与定位

- **是什么**：框架内置的**第 0 号数字员工** `system_secretary`（template_id 0）——她不是 8 个业务数字员工的并列角色，而是站在他们之上的**总入口 + 总调度**：
  - 自己回答：系统使用方法、数据字典、功能分布、业务流/数据流（知识库检索）；
  - 识别业务诉求：转派给对应数字员工（销售/客服/营销策划/数据分析…），用户全程只跟一个入口对话；
  - 指路代步：直接给出菜单跳转、表单预填，"说说说"代替"点点点"。
- **知识来源**：① 框架每次发版自动重建的系统知识库；② 像 module-loader 一样自动发现的下游项目（scrm 等）文档知识库。
- **模型**：国产 flash 级模型，默认阿里云百炼 `qwen-flash`，备选 `deepseek-v3` / `mimo-2.5`（非 pro）。
- **计费**：平台买单——**不消耗租户任何积分/token**，用量仅记账观测。

## 设计原则（继承既有铁律）

- 分层复用：跑在现有 AgentRuntime（ReAct+SSE）、ToolRegistry、BuiltinAgentTemplates、AiAssistant 前端之上，不新建推理循环/工具表/前端面板。
- 可选性：旁挂增强，独立 feature category `secretary`，关闭时零 DOM 零请求；检索/模型失败 fail-open 降级为"引导用户去对应菜单"。
- 身份模型：面向 Operator（console 后台）；会话隔离沿用 agent_conversations；系统知识库为平台级静态资产（tenant_id=0）。
- 转派可控：转派前明示"这个问题交给销售助手处理，继续吗"，人确认后切换（可配置为静默转派）。

## 一、模型与计费配置（.env 平台级）

### 1. config/ai.php 新增 bailian provider（OpenAI 兼容，启用现有注释块）

```env
# 平台级小秘书 AI 配置（不占用租户配额）
AI_BAILIAN_BASE_URL=https://dashscope.aliyuncs.com/compatible-mode/v1
AI_BAILIAN_API_KEY=sk-xxx
SECRETARY_AI_PROVIDER=bailian
SECRETARY_AI_MODEL=qwen-flash
SECRETARY_AI_FALLBACK_MODEL=deepseek-v3
SECRETARY_ENABLED=true
```

- `config/ai.php` 增加 `secretary` 配置段（provider/model/fallback/enabled），bailian models 列表含 `qwen-flash`、`qwen-turbo`、`deepseek-v3`、`mimo-2.5`；
- 模板 0 的 `model_config` 不写死，运行时从 `config('ai.secretary')` 解析——**换模型只改 .env，零代码零数据变更**。

### 2. 平台买单（不扣租户配额）

- AgentRuntime 用量记账处：小秘书 Agent（role=system_secretary）的调用**跳过 AiUsageService 租户配额扣减**，用量记入平台账（tenant_id=0）供观测与成本核算；
- 租户的 AiConfigService category 开关对 `secretary` 独立：平台可全局开/关，租户可自主隐藏入口，但不产生任何租户侧计费。

## 二、知识库底座（Ai 模块新增 SystemKb 子域）

### 1. docs-as-knowledge 目录约定（对齐 module-loader 优先级）

| 来源 | 路径约定 |
|---|---|
| 框架全局 | `docs/kb/*.md` |
| 框架模块 | `src/Modules/<X>/resources/kb/*.md` |
| vendor 拆分包 | `vendor/dsplat/*/resources/kb/*.md` + 框架核心包 docs/kb |
| 下游项目模块 | `app/Modules/<X>/resources/kb/*.md`（**零配置自动发现**） |
| 下游项目全局 | 项目根 `docs/kb/*.md` |

覆盖规则与前端视图一致：项目 > vendor 包 > 框架核心。md 支持 frontmatter（title/module/audience/version/locale）：`audience` 区分 operator/internal（internal 条目不进入租户可见检索结果），`locale` 支持 zh/en 双语语料。

### 2. SystemKbRegistry（发现器）

仿 ModuleRegistry 扫描上述路径，产出文档清单（source, module, path, title, checksum, version）。

### 3. 自动生成文档（发版刷新）

`php artisan secretary:kb:generate` 产出三份机器文档：
- **数据字典**：扫描框架+下游 migrations/Model casts → 表/字段/枚举说明；
- **功能分布图**：扫描模块路由 + 前端 nav sections → "功能 → 模块 → console 菜单路径"映射（指路能力的事实来源）；
- **数字员工名录**：扫描 BuiltinAgentTemplates + 下游模板（如 ScrmAgentTemplates）→ 每个数字员工的职责/能力/工具清单（**转派路由的事实来源**）。
- **版本变更**：CHANGELOG 最新段落 + 版本号。

### 4. 索引与检索（纯文件型，零 DB）

- 无数据库表、无 embedding、无同步命令；
- `SystemKbRegistry` 零配置发现 kb 文档（app/Modules > docs/kb > vendor/dsplat > src/Modules）；
- `SystemKbSearchService` 运行时直接读文件 → 按 ## 标题内存分块 → 中文 bigram 关键词打分；
- 知识库是随版本发布的文件资产，部署初期只需配一个 chat 小模型即可使用小助手。

### 5. 知识库构建工具链（生产侧，补齐"谁来写文档"）

现状是框架没有数据字典和模块使用手册，26 个模块文档不能靠人手写，必须工具化：

- **`secretary:kb:build [--module=X] [--changed]`（AI 辅助文档构建器）**：扫描模块的 Routes/Controllers/Services/migrations/前端视图，用 LLM（同 bailian 配置）起草该模块使用手册草稿（功能说明、操作步骤、业务流），输出到 `src/Modules/<X>/resources/kb/`，**人审后提交入库**——文档是代码资产，构建发生在开发/发版时而非运行时；`--changed` 只重建对比上个 tag 有代码变更的模块，实现"每发版增量更新文档"；
- **覆盖率守卫**：`architecture_guard.py` 新增检查——每个启用模块必须有至少一份 kb 文档、frontmatter 合法、文档内引用的路由/表名真实存在（覆盖缺口=0、失效引用=0，机器自证完备）；pre-commit + CI 双卡口；
- **`secretary:kb:eval`（golden questions 质量回归）**：维护标准问答集（"XX 功能在哪 → 期望命中文档/路由"），索引重建后跑检索命中率，低于阈值报警——防止文档改烂无人察觉。

### 6. 发版/部署闭环

- 发版时序：`kb:build`（人审草稿）→ commit → tag → split 分发（kb 语料随包）；
- 下游 composer update 拉到新框架包即自动获得新文档，无需任何后置命令。

## 三、小秘书 Agent 本体

### 1. BuiltinAgentTemplates 新增第 0 号模板

- `template_id: 0`，`template_key: system_secretary`，is_builtin，默认随租户开通（`secretary:install` 批量开通命令）；
- system_prompt 要点：友好向导人设；只依据知识库回答，答不出就承认并给菜单路径；识别到业务诉求先说明将转派给哪位数字员工；操作类请求给步骤后问"要我带你去吗"。

### 2. 新增工具（注册进现有 ToolRegistry，category `secretary`）

| slug | 作用 |
|---|---|
| `system_kb_search` | 混合检索系统知识库，返回带模块/文档来源的片段 |
| `get_data_dictionary` | 按表名/模块结构化查数据字典（不走向量） |
| `navigate_to` | 返回 `{route_path, label}`，前端确认后跳转（只读） |
| `list_agents` | 列出本租户已启用数字员工及职责（来自名录文档+agents 表） |
| `delegate_to_agent` | 返回 `{agent_id, reason, handoff_message}`，前端确认后把会话切换到目标数字员工并携带上下文摘要 |

转派实现：不在后端嵌套调用 Agent，而是**前端会话切换**（AssistantPanel 切换目标 agent_id，携带小秘书生成的 handoff 摘要作为首条消息）——保持单层 ReAct，可观测、可中断。

### 3. "说说说"前端（扩展现有 AiAssistant，不新建组件）

- 可用性回退：当前模块业务 Agent 未启用时**回退到小秘书**（秘书启用即全站可对话），替代现在的"🔒 未启用"死胡同；
- SSE 事件新增两类：
  - `navigate` → ChatMessage 渲染"带我去 →"按钮，点击 `router.push`；
  - `delegate` → 渲染"转给 XX 数字员工 →"卡片，确认后面板切换 agent 并注入 handoff 摘要；
- 表单预填复用现有 FormFillSuggestion 通道；
- 快捷指令追加："这个系统怎么用"、"XX 功能在哪"、"帮我找个数字员工"。

## 四、文件落点（框架仓库）

- `src/Modules/Ai/Services/SystemKb/`：SystemKbRegistry / SystemKbSearchService / SystemKbDocBuilder（AI 起草）/ DataDictionaryGenerator / FeatureMapGenerator / AgentDirectoryGenerator / GoldenQuestionEvaluator
- `src/Modules/Ai/Services/Tool/`：SystemKbSearchTool / DataDictionaryTool / NavigateTool / ListAgentsTool / DelegateToAgentTool
- `src/Console/Commands/`：SecretaryKbBuild / SecretaryKbGenerate / SecretaryKbEval / SecretaryInstall
- `scripts/architecture_guard.py`：kb 覆盖率/frontmatter/引用有效性检查
- `src/Modules/Ai/Services/SystemKb/`：纯文件型知识库（零 DB，无迁移文件）
- `BuiltinAgentTemplates.php`：模板 0；`config/ai.php`：bailian provider + secretary 段；`AiServiceProvider`：工具与服务注册
- AgentRuntime 记账处：secretary 跳过租户配额
- `src/Modules/Ai/resources/console/ai-assistant/`：回退逻辑 + navigate/delegate 事件
- 语料种子：框架 `docs/kb/` 首批手册；scrm 侧按约定放 `app/Modules/<X>/resources/kb/`

## 分期交付

1. **P0 知识底座 + 模型配置**：目录约定 + Registry + 两表 + sync/generate 命令 + system_kb_search 工具 + bailian/.env 配置 + 平台记账旁路
2. **P1 知识库构建工具链**：kb:build 文档构建器（首批 26 个框架模块文档批量起草+人审入库）+ 覆盖率守卫 + kb:eval 问答集
3. **P2 秘书本体**：模板 0 + install 命令 + navigate/回退前端 + 快捷指令
4. **P3 调度闭环**：数字员工名录生成 + list_agents/delegate 工具 + 前端转派卡片 + deploy 钩子 + scrm 模块 kb 文档（kb:build 对 app/Modules 同样适用）

## 测试计划（精准测试规则）

- `tests/SystemKbRegistryTest.php`、`tests/SystemKbSearchServiceTest.php`、`tests/SystemKbDocBuilderTest.php`（mock LLM 验证草稿结构与增量判定）、`tests/SecretaryTemplateTest.php`（模板 0 克隆/工具绑定/model_config 来自 config）、`tests/SecretaryBillingTest.php`（租户配额零扣减）、`tests/Schema/` 覆盖新表；
- AI_DRIVER=mock 验证关键词降级与转派结构化输出；单文件执行，不跑全量。

## 假设

- 系统知识库平台级共享（全租户同一套文档）；
- BAILIAN_API_KEY 为平台密钥，配置于服务器 .env，不入库不进代码；
- `navigate_to`/`delegate_to_agent` 均为只读结构化输出，业务写操作仍走"草稿→人确认"通道；
- 转派后的对话消耗归属目标数字员工，按现行租户计费规则执行（只有小秘书本人免租户配额）。
