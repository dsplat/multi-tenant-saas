# 待办清单

> 跨迭代遗留项与待决策项跟踪。已完成的条目移入文末「已完成归档」。
> 更新时间：2026-08-23

---

## 〇、H5 SEO/GEO 优化（方案 A + 方案 B）

> 详细方案：`scrm-platform/docs/2026-08-21-h5-seo-geo-plan.md`（decision-complete，含验证矩阵与部署路径）
> 状态：已出方案文档，**未动工**。用户确认后从 M1 开始。

### TODO-SEO-001: M1 方案 A —— SPA 基础 SEO 化（前端）

**优先级**: 高（前置，0.5-1 天）

**仓库**: scrm-platform-front

**内容**:
- `apps/h5/src/manifest.json` 开 history 路由（`h5.router.mode="history"`，nginx fallback 已就绪）
- `apps/h5/src/main.ts` 入口兼容旧 hash 链接（`#/pages/` → 301 新 URL，必须与路由切换同步上线）
- `apps/h5/index.html` 补 title/description/OG/品牌 JSON-LD（注意：先构建验证 uni-app 是否覆盖 title，决定改 index.html 还是 pages.json）
- 新增 `useSeoMeta` composable（title + description + canonical），接入 5 个关键页面（首页/课程列表/课程详情/商品详情/活动详情）

**完成标准**: 新 URL 可访问且刷新不 404；旧 hash 链接自动跳转；构建产物 index.html 元数据非空

---

### TODO-SEO-002: M2 方案 B-1 —— nginx 分流 + 课程页 PHP 直出（后端）

**优先级**: 高（1-2 天）

**仓库**: multi_tenant_saas（nginx 基桩）+ scrm-platform（PHP 层）

**内容**:
- 框架：`tenant-server.conf.stub` 新增 `{{SEO_DIRECT_OUT_LOCATIONS}}` 占位符 + `NginxConfigService` 渲染能力，路径列表由 `config/domain.php` 的 `seo_direct_out_paths` 注入（默认空，落地在项目）；新增 `$is_seo_bot` map（普通搜索引擎爬虫 + 复用 `$is_ai_bot`）
- 分流规则：`location ^~ /h5/pages/{course,shop,event}/...`（最长前缀优先于现有 `^~ /h5/`），爬虫 → PHP 直出，真人 → 静态 SPA；location 内须自行补 `X-Robots-Tag`（add_header 继承坑）
- scrm：新增 `app/Modules/Seo/`（SeoController + SeoRenderService + Blade 模板），复用框架 CourseService 公开数据
- 中间件纪律：挂 IdentifyTenant、**不挂** tenant.ensure（对齐 console SPA 403 教训）；SeoDirectOut 兜底把真人 302 回 SPA

**安全红线**: 付费章节 content/file_url 禁止直出（数据层白名单，模板零权限判断）；未发布内容 404

**完成标准**: Googlebot/Baiduspider/GPTBot 拿完整 HTML+JSON-LD；Chrome UA 拿 SPA；付费内容不泄露；基桩安全矩阵不回归

---

### TODO-SEO-003: M3 方案 B-2 —— 商品/活动直出 + JSON-LD + sitemap（后端）

**优先级**: 中（1 天，可与 M2 并行）

**仓库**: scrm-platform + multi_tenant_saas（robots.txt 联动）

**内容**:
- 商品详情/活动详情直出（复用 Product/Event Service）
- JSON-LD 补齐：Course/Product/Event/Organization/BreadcrumbList（GEO 核心）
- `/sitemap.xml`：按域名识别租户输出已发布内容 URL；基桩 robots.txt 的 `$seo_allowed=1` 分支追加 Sitemap 行
- 直出页 `Cache-Control: public, max-age=600`

**完成标准**: 方案文档 §7 验证矩阵全过

---

### TODO-SEO-004: 实施前待确认项

1. 课程评价数据源与字段（entity_type='course' 多态表，昵称脱敏规则）
2. 活动详情 URL 参数形态（event_id? campaign_id?），与直出路由对齐
3. 商品详情 URL 参数形态（query 还是路径参数），决定 nginx location 写法
4. uni-app 构建产物中 index.html 的 title 处理行为（决定 A3 二选一）
5. `sitemap.xml` 对二级域名（seo_allowed=0）的响应策略（200 空 / 404）
6. JSON-LD priceCurrency 货币符号来源（租户配置项是否存在）

**部署约束**: 框架基桩改动 → push → scrm `composer update dsplat/*` + commit lock → incremental → 服务器 `domains:generate-nginx` + nginx reload；方案 A 走 front 仓 `deploy.py module --app h5`

---

## 一、项目大脑（Thread/Brain）遗留项

> 背景：Phase 0-3 已全部上线（框架 5e8b2ff / scrm 816bf6e），E2E 场景 0-3 验收通过。
> 主闭环（策划→定稿→自动跟踪→巡检→主动提醒）完整可用，以下为边缘增强项。

### TODO-BRAIN-001: thread_track 主动提议积极性调优

**优先级**: 低（有手动兜底）

**现状**: 工具已上线且在秘书模板中，用户明确说"帮我持续跟进"即可触发确认卡片。但 E2E 场景 2 中 AI 对建议事项仅给文本列表，未主动提议建立跟踪。

**影响面**: 仅"未走定稿流程的松散事项"——AI 不主动提议则不进入巡检与摘要注入范围；已定稿计划自动 tracked 不受影响。

**方向**: 秘书模板 system_prompt 引导词调优（识别出值得跟进的事项时更积极地提议 thread_track）。等真实使用数据积累后再调，避免过度提议骚扰用户。

---

### TODO-BRAIN-002: Phase 4 会话-脉络精确关联

**优先级**: 中（等前三层运行数据验证后启动）

**现状**: AI 跨会话"事实记忆"完整（计划/任务/健康状态靠摘要注入 + thread_review）；但"对话语境记忆"缺失——上次会话口头说的约束（如预算、渠道偏好）若未落入 plan_doc，新会话不知道。关联会话摘要目前靠主题匹配，可能漏配。

**方案**（计划原文 Phase 4）:
- conversation.metadata 记锚点引用（anchor_type/anchor_id 或 plan_id）
- resolve 附同脉络最近会话 summary，实现"接着上次聊"
- thread_review 的关联会话摘要从主题匹配升级为精确关联

---

### TODO-BRAIN-003: 巡检异常 LLM 分析增强（默认关闭）

**优先级**: 低

**现状**: `thread:health-check` 纯规则零 LLM 已上线；`ai.brain.background_reasoning` 开关与 brain 计费通道设计已定但未实现。

**方案**: 巡检发现异常的脉络追加一次 LLM 分析（推断遗漏/生成建议存入 metadata.health.suggestions），走独立 `brain` 计费通道：用量记真实 tenant_id（scenario=brain），计费主体可配（`ai.brain.billing` = platform/tenant），硬限额每脉络每日一次 + 单次 token 上限。

---

### TODO-BRAIN-004: capability-map 覆盖面扩展

**优先级**: 低（渐进式）

**现状**: 首版 next-actions 段落覆盖 Event 相关模块（Campaign/Poster/MassMessage/Coupon 链路）。

**方向**: 其余 scrm 模块按需在各自 `resources/kb/` 补 next-actions 约定段落，`secretary:kb:index` 部署时自动收录，零中心化配置。

---

## 二、历史待决策项

### TODO-SEC-001: 18 个 SCRM L2 工具是否挂载系统小秘书

**优先级**: 待产品决策

**现状**: 系统小秘书仅挂 3 个 L2 试点工具（tag_customer / create_script_draft / save_oauth_config）+ 本轮新增的 campaign_plan_commit / thread_track / thread_untrack；其余 SCRM L2 工具未挂。L2 确认门（流式 + IM 文本确认）已全链路可用，挂载无技术障碍。

---

## 三、console 场景化导航 × 开放平台 API 场景化封装（两个优化方向）

> 方向文档：`scrm-platform/docs/2026-08-21-scenario-nav-and-open-api-directions.md`（2026-08-21 讨论会定稿 + 2026-08-22 澄清，含目标形态 / 双角色模型 / iBot 链路 / 验收示例）
> 状态：方向已确认，**未动工**，待拆解为实施计划。

### TODO-NAV-001: 场景落地页 + AI 模式导航（方向一）

**优先级**: 高（产品体验主线）

**仓库**: scrm-platform（console 前端）

**内容**:
- 大模块路由 → 场景化落地页：数字员工卡片（展示但不管理，老板只对接小秘书）/ 场景直达卡片（Skills 沉淀，运营越用越丰富）/ 进行中任务卡片（确定性接口取数）
- AI 模式 × 传统模式双菜单切换；现有 navSectionsRaw 三层架构保留为传统模式底座

**完成标准**: 业务用户（孙丽姐评测活动场景）不走「创建活动→活码→关联→文案→海报」链路，从场景卡片直达完成

---

### TODO-NAV-002: 小秘书预设指令与场景扩充（方向一）

**优先级**: 高

**仓库**: scrm-platform / multi_tenant_saas（BuiltinAgentTemplates）

**内容**: 老板只对接小秘书一人，只下需求；小秘书内置更多预设指令与场景（查数据 / 看活动 / 找入口），数字员工由小秘书调度，不建员工管理界面

**完成标准**: 老板全程只与小秘书交互即可完成业务闭环

---

### TODO-NAV-003: 双角色模型拆分 + iBot 链路（方向一）

**优先级**: 中（依赖 NAV-002 与 iBot 现状评估）

**内容**: 小秘书（个人实时事务）× 首席运营官（调度数字员工）职责分离；小秘书承载到手机端 iBot，链路 老板(iBot) → 小秘书 → 运营官 → 数字员工（例：手机查「今天某活动数据」）

**完成标准**: 手机 iBot 可完成「问数据 → 运营官取数 → 回传手机」闭环

---

### TODO-API-001: 平台开放能力盘点（方向二，企微先行）

**优先级**: 高（方向二前置）

**仓库**: scrm-platform（EnterpriseWechat 相关）

**内容**: 按企微官方开放 API 文档分析「开放能力 × 可支撑场景」，产出盘点文档；不搞全量对接、不做「API 菜单」

**完成标准**: 盘点文档覆盖主要运营动作（群发 / 接龙 / 入群 / 消息确认）并给出自动化边界

---

### TODO-API-002: 运营动作场景封装 + AI 起草员工确认发送（方向二）

**优先级**: 中（依赖 API-001）

**内容**: 以运营动作（社群接龙 / 评测活动）为封装单元，编排多个接口成 Skill；「AI 起草 + 员工确认」：AI 备好待发内容 → 企微通知绑定员工 → 员工点「同意」 → Bot 发送直达群；人只做审一眼 + 点一下

**完成标准**: 一个运营动作从发起到发送，人的操作仅为一次确认点击

---

## 已完成归档

- ✅ 2026-07-31 项目大脑 Phase 0-3 全量上线 + E2E 场景 0-3 验收（L2 确认门 / capability-map / 摘要注入 / thread_review / AssetProbe / health-check）
- ✅ 2026-07-31 tool_calls 落库格式 400 修复（normalizeToolCalls + reconcileToolCallPairs 配对裁剪，框架 5323880）
- ✅ 2026-07-31 锚点预检修复（collectRequiredAnchors 一次性预检 + missing_anchors 结构化返回 + draft 单锚点纪律，框架 5e8b2ff）
