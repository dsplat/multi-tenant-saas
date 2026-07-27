# 系统小秘书上线操作手册（运维版）

> 适用对象：生产环境运维人员
> 关联设计文档：`docs/system-secretary-design.md`
> 涉及组件：第 0 号数字员工（system_secretary）、系统知识库（system_kb_documents / system_kb_chunks）

---

## 1. 功能概述

系统小秘书是框架内置的第 0 号数字员工，为所有租户提供系统使用答疑、页面导航与业务数字员工转派。核心特性：

- **模型费用平台买单**：不消耗租户任何积分/token，模型配置由 `.env` 平台级控制
- **知识来源**：系统知识库，发版时通过命令自动重建（docs-as-knowledge，零人工配置）
- **fail-open 设计**：向量嵌入失败自动降级为纯关键词检索，不阻断上线流程

---

## 2. .env 配置项说明

### 2.1 百炼 Provider（必配）

| 配置项 | 默认值 | 说明 |
|---|---|---|
| `AI_BAILIAN_API_KEY` | 空 | **必填**。阿里云百炼 API Key。缺失时：对话不可用、向量嵌入降级为关键词检索 |
| `AI_BAILIAN_BASE_URL` | `https://dashscope.aliyuncs.com/compatible-mode/v1` | 百炼 OpenAI 兼容端点，一般无需改动 |

### 2.2 小秘书专属配置（SECRETARY_*）

| 配置项 | 默认值 | 说明 |
|---|---|---|
| `SECRETARY_ENABLED` | `true` | 平台级总开关。`false` 时前端零入口、后端拒绝秘书会话 |
| `SECRETARY_AI_PROVIDER` | `bailian` | 主模型 provider，须在 `config/ai.php` providers 中已定义 |
| `SECRETARY_AI_MODEL` | `qwen-flash` | 主对话模型 |
| `SECRETARY_AI_FALLBACK_PROVIDER` | `bailian` | 降级 provider |
| `SECRETARY_AI_FALLBACK_MODEL` | `deepseek-v3` | 主模型失败时的降级模型 |
| `SECRETARY_AI_TEMPERATURE` | `0.3` | 采样温度 |
| `SECRETARY_AI_MAX_TOKENS` | `2000` | 单次回复 token 上限 |
| `SECRETARY_AI_MAX_TOOL_CALLS` | `5` | 单轮会话工具调用上限 |
| `SECRETARY_EMBEDDING_PROVIDER` | `bailian` | 知识库向量化 provider |
| `SECRETARY_EMBEDDING_MODEL` | `text-embedding-v3` | 向量化模型。**置空 = 显式关闭向量检索**（纯关键词模式，功能可用但召回精度下降） |
| `AI_TIMEOUT` | `60` | AI HTTP 请求超时秒数（对话与 embedding 共用） |

> 换模型只需改 `.env` 后 `php artisan config:clear`，零代码、零数据变更（模板 0 的 model_config 不落库，运行时从配置解析）。

---

## 3. 上线执行顺序

### 3.1 前置条件

- 框架代码已按标准流程发布（git push main → split → 下游 `composer update dsplat/*` → deploy.py incremental）
- `.env` 已配置第 2 节全部必填项，尤其 `AI_BAILIAN_API_KEY`

### 3.2 命令序列（顺序不可颠倒）

在应用根目录依次执行：

```bash
# ① 建表：system_kb_documents / system_kb_chunks
php artisan migrate --force

# ② 生成机器文档 → docs/kb/generated-*.md
#    （数据字典 / 功能分布图 / 数字员工名录）
php artisan secretary:kb:generate

# ③ 同步知识库索引：发现 kb 文档 → checksum 增量 → 分块 + embedding
php artisan secretary:kb:sync

# ④ 为全部租户安装第 0 号员工（幂等，已安装自动跳过）
php artisan secretary:install
```

命令说明：

| 命令 | 幂等性 | 可选参数 |
|---|---|---|
| `secretary:kb:generate` | 是（覆盖固定文件名） | `--only=dictionary\|features\|agents` 仅生成单个文档 |
| `secretary:kb:sync` | 是（checksum 增量：未变化跳过、已删除清理） | 无 |
| `secretary:install` | 是（已存在秘书的租户自动跳过） | `--tenant=<tenant_id>` 仅安装指定租户 |

### 3.3 日常发版

每次发版后与 migrate 同批执行 ② + ③ 即可刷新知识库（增量，未变化文档零开销）。新租户开通后执行 `secretary:install --tenant=<id>`（或全量跑一次 `secretary:install`，幂等）。

---

## 4. 常见故障排查

### 4.1 百炼 API 调用失败（对话无响应 / 报错）

**现象**：前端秘书对话报错或长时间无响应。

排查步骤：

```bash
# 1. 确认 key 已配置且已生效
php artisan tinker --execute="echo config('ai.providers.bailian.api_key') ? 'key OK' : 'key MISSING';"

# 2. 服务器直连验证百炼端点连通性
curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer $AI_BAILIAN_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"model":"qwen-flash","messages":[{"role":"user","content":"ping"}],"max_tokens":8}' \
  https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions
```

| 返回码 | 原因 | 处理 |
|---|---|---|
| `401` | API Key 无效/过期 | 到百炼控制台重新生成，更新 `.env` 后 `config:clear` |
| `429` | 限流/欠费 | 检查百炼账户余额与 QPS 配额 |
| `404` | 模型名错误 | 核对 `SECRETARY_AI_MODEL` 是否在百炼已开通 |
| 超时/无法连接 | 服务器出网限制 | 检查防火墙/代理是否放行 dashscope.aliyuncs.com:443 |

> 主模型失败时 AgentRuntime 会自动降级到 `SECRETARY_AI_FALLBACK_MODEL`；若两者同 provider（默认都走百炼），key 失效会导致双双失败。

### 4.2 向量嵌入超时 / 失败

**现象**：`secretary:kb:sync` 执行慢，或 `laravel.log` 出现：

```
[SystemKbEmbedder] embedding 请求失败，降级关键词
[SystemKbEmbedder] embedding 异常，降级关键词
```

**关键认知：embedding 失败不阻断上线**。Embedder 是 fail-open 设计——任何失败（key 缺失/网络异常/超时）该分块的 `embedding` 存 `null`，检索侧自动降级纯关键词，命令仍会正常结束。

处理路径：

1. **偶发超时**：适当调大 `AI_TIMEOUT`（如 `120`），重跑 `secretary:kb:sync`。
   > 注意 checksum 增量：文档内容未变时会被跳过、不会补 embedding。需强制重建时先清空再同步：
   > ```bash
   > php artisan tinker --execute="\MultiTenantSaas\Modules\Ai\Models\SystemKbChunk::query()->delete(); \MultiTenantSaas\Modules\Ai\Models\SystemKbDocument::query()->delete();"
   > php artisan secretary:kb:sync
   > ```
2. **持续失败**：按 4.1 排查百炼连通性；确认 `SECRETARY_EMBEDDING_MODEL`（text-embedding-v3）已在百炼开通。
3. **主动放弃向量检索**：`SECRETARY_EMBEDDING_MODEL=` 置空 + `config:clear`，索引与检索均走纯关键词，零外部依赖。

检查向量覆盖率：

```sql
SELECT COUNT(*) AS total,
       SUM(CASE WHEN embedding IS NULL THEN 1 ELSE 0 END) AS no_embedding
FROM system_kb_chunks;
```

`no_embedding > 0` 表示部分分块处于关键词降级状态。

### 4.3 secretary:install 安装数不符预期

- 命令内部逐租户切换租户上下文（Agent 表受租户作用域 fail-closed 约束），执行完自动恢复。若某租户被跳过，先确认该租户是否已存在秘书（见 5.1 验证 SQL）。
- 仅需补装单个租户：`php artisan secretary:install --tenant=<id>`。

### 4.4 前端看不到小秘书入口

按序检查：

1. `SECRETARY_ENABLED=true` 且已 `config:clear`（`false` 时前端零入口，属预期行为）
2. 该租户 agents 表存在 `role = 'system_secretary'` 记录（见 5.1）
3. console 前端已随发版重新构建部署（前端资源不在后端 deploy.py 同步范围内，需单独发布）

### 4.5 知识检索返回空

1. 确认知识库已入库（见 5.2）；`documents = 0` 说明未执行 ②③ 或 `docs/kb/` 未随代码部署
2. `secretary:kb:generate` 输出中若有 `生成 xxx 失败` 警告（单生成器 fail-open 不阻断），按警告信息排查后用 `--only=` 重新生成对应文档
3. 纯关键词模式下召回依赖字面命中，属精度下降而非故障

---

## 5. 上线验证清单

逐项确认，全部通过即上线完成：

### 5.1 第 0 号员工已安装

```sql
-- 每个活跃租户应恰好 1 条
SELECT tenant_id, agent_id, name, enabled
FROM agents
WHERE role = 'system_secretary'
ORDER BY tenant_id;

-- 对账：数量应等于租户总数
SELECT
  (SELECT COUNT(*) FROM tenants) AS tenants,
  (SELECT COUNT(*) FROM agents WHERE role = 'system_secretary') AS secretaries;
```

- [ ] 每个租户存在且仅存在 1 条 `system_secretary`，`enabled = 1`

### 5.2 知识库已建立

```sql
SELECT
  (SELECT COUNT(*) FROM system_kb_documents) AS documents,
  (SELECT COUNT(*) FROM system_kb_chunks) AS chunks,
  (SELECT COUNT(*) FROM system_kb_chunks WHERE embedding IS NOT NULL) AS vectorized;
```

- [ ] `documents > 0`（含 `docs/kb/` 手册 + 3 份 generated- 机器文档）
- [ ] `chunks > 0`
- [ ] `vectorized ≈ chunks`（允许为 0 —— 关键词降级模式，需知悉即可）

### 5.3 知识检索返回结果

```bash
php artisan tinker --execute="
\$r = app(\MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbSearchService::class)->search('积分');
echo '命中 '.count(\$r).' 条'.PHP_EOL;
foreach (\$r as \$hit) { echo '- '.\$hit['title'].' / '.\$hit['heading'].PHP_EOL; }
"
```

- [ ] 命中数 > 0，且标题/小节与查询词语义相关

### 5.4 端到端对话（前端）

- [ ] 以租户运营身份登录 console 后台，AI 助手默认出现小秘书入口
- [ ] 提问「系统有哪些数字员工」→ 正常回复并列出业务员工（验证 `list_agents` 工具）
- [ ] 提问「怎么给客户发优惠券」→ 回复含知识库内容或页面导航（验证 `system_kb_search` / `navigate`）
- [ ] 观察百炼控制台调用量增长，且对应租户 token 用量 / 积分**无扣减**（平台买单验证）

### 5.5 日志无异常

```bash
tail -100 storage/logs/laravel.log | grep -i "secretary\|SystemKb"
```

- [ ] 无 ERROR 级日志（`[SystemKbEmbedder] … 降级关键词` 的 WARNING 可接受，对照 5.2 的 vectorized 数即可）

---

## 6. 回滚预案

| 场景 | 操作 | 影响 |
|---|---|---|
| 秘书回答异常/成本失控 | `.env` 置 `SECRETARY_ENABLED=false` + `php artisan config:clear` | 前端入口消失、后端拒绝秘书会话，业务数字员工不受影响 |
| 仅向量检索异常 | `SECRETARY_EMBEDDING_MODEL=` 置空 + `config:clear` | 降级纯关键词检索，功能保留 |
| 模型质量/费用问题 | 修改 `SECRETARY_AI_MODEL` / `FALLBACK_MODEL` + `config:clear` | 即时生效，无需重装员工 |

均为配置级开关，无需回滚代码或数据库。
