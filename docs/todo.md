# 待办清单

> 跨迭代遗留项与待决策项跟踪。已完成的条目移入文末「已完成归档」。
> 更新时间：2026-08-23（归档 WECOM-002 / WECOM-004）

---

## 〇、H5 SEO/GEO 优化（方案 A + 方案 B）

> 详细方案：`scrm-platform/docs/2026-08-21-h5-seo-geo-plan.md`（decision-complete，含验证矩阵与部署路径）
> 状态：**M1 已完成**（front a9b2e4d，产物部署验证待做），下一步 M2（SEO-002）。

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

### TODO-API-002: 运营动作场景封装 + AI 起草员工确认发送（方向二）

**优先级**: 中（依赖 API-001）

**内容**: 以运营动作（社群接龙 / 评测活动）为封装单元，编排多个接口成 Skill；「AI 起草 + 员工确认」：AI 备好待发内容 → 企微通知绑定员工 → 员工点「同意」 → Bot 发送直达群；人只做审一眼 + 点一下

**完成标准**: 一个运营动作从发起到发送，人的操作仅为一次确认点击

---

## 四、企微群运营与运营引导收尾（2026-08-23）

> 背景：企微群运营全链路（scrm 0b1380a）与通栏引导卡片（scrm 49ddd3a）已提交；
> 框架配套（会话存档 RSA→AES 解密 / WechatWorkExternalEvent 分发 / 秘书群运营工具）随本次提交发布。

### TODO-WECOM-001: 企微会话存档 + 外部事件全链路部署生效

**优先级**: 高（框架发布后立即执行）

**仓库**: multi_tenant_saas + scrm-platform

**内容**:
- 框架提交（会话存档组合解密 / 外部事件分发 / 秘书群运营工具）→ split → scrm `composer update dsplat/*` → `deploy.py incremental`；**顺序必须框架先行**——scrm `ChatArchiveSyncService::fetchFromWeCom` 已调用框架新方法 `decryptChatData`，框架未发布前生产静默失败（catch 吞错返回 null）
- 生产配置：channels 表 `type=wechat_work` 渠道 `metadata.session_archive.private_key`（RSA PEM，与企微后台会话存档公钥配对）；企微管理后台开通「会话内容存档」付费能力

**完成标准**: 生产 ChatArchive 拉取解密成功落库；`ExternalWechatWorkEventListener`（scrm Community 模块）收到入群/退群/添加客户事件并触发成员同步

---

### TODO-WECOM-003: AI 成串场景生产验证

**优先级**: 中（依赖 WECOM-001 部署）

**仓库**: scrm-platform

**内容**:
- 场景文档已入库（`docs/2026-08-22-ai-chained-scenarios.md`，f353954）
- 按文档 §8/§9 验证：秘书职责 13（群运营代操作）生效；8 个群运营工具（get_community_list / set_group_announcement / trigger_chat_archive_sync / list_chat_archives / search_chat_archive / list_external_contacts / list_group_bot_rules / list_welcome_messages）可用；L2 串行铁律（每群一张确认卡）

**完成标准**: 场景一~五小秘书可走通；能力边界（§7）如实转述不硬编



---

## 五、私域运营缺陷遗留项（2026-08-21 深度审计）

> 背景：对私域运营功能做「需求设计 × 实现现状」全量比对与历史溯源。
> 根因：需求先行但无验收映射、AI 铺量期无铁律约束、测试偏斜（重 console/API、轻 H5/逆向路径）→ 缺陷静默沉淀。
> 已解决不重复登记：H5 活动报名链路（C4，event.ts 已迁移 activities）、交易链路 C1-C3/H1-H3/M1（8-21 修复）、企微客户联系/群运营全链路（scrm 0b1380a）。

### TODO-SCRM-001: 企微聊天侧边栏（JSSDK）未实现

**优先级**: 中（需产品确认是否排期）

**仓库**: scrm-platform

**内容**: 需求规划模块 6（内容中心）要求「企微聊天侧边栏集成：客户画像、话术库、素材库、营销活动，一键发送」。当前全仓无 JSSDK/侧边栏实现（NavContextController 仅为导航上下文），属「设计有、实现无」的静默消失项。

**完成标准**: 按 JSSDK 方案实现侧边栏 H5，或在需求文档中显式标注延期及理由

---

### TODO-SCRM-002: 自动拉群能力缺失

**优先级**: 中

**仓库**: scrm-platform

**内容**: 需求规划模块 4 要求「基于客户标签/行为/阶段自动邀请入群，支持精准分流规则」。当前仅有入群/退群/添加客户事件触发的成员同步（ExternalWechatWorkEventListener），无「按条件自动邀请入群」引擎。

**完成标准**: 实现自动拉群规则（触发条件 → 目标群 → 分流）或需求文档显式砍掉

---

### TODO-SCRM-003: 客户旅程可视化画布降级为表单

**优先级**: 低

**仓库**: scrm-platform

**内容**: 需求规划模块 5 要求「多阶段营销流程画布，支持分支判断、延时等待、A/B 测试」；实现为 RuleEditor 表单化规则（触发→动作），AutomationABTestService 有服务层但无 UI 入口。

**完成标准**: 画布落地，或需求文档更新为表单化实现并记录决策

---

### TODO-SCRM-005: scrm_automation 功能开关默认关闭

**优先级**: 中（待产品确认）

**仓库**: scrm-platform

**内容**: `config/tenancy.php` 中 `scrm_automation` 自 2026-07-11 起 `inactive` 0%，自动化规则引擎（触发→动作已完整实现）在租户侧不可见。灰度开关无「功能完成→打开」生命周期管理，灰度变成封存。

**完成标准**: 确认功能稳定性后打开开关，或明确长期关闭原因并记录

---

### TODO-SCRM-006: scrm_activities 功能开关默认关闭

**优先级**: 中

**仓库**: scrm-platform

**内容**: `scrm_activities` 2026-08-19 随 Activity 统一模块（取代 Campaign/Event）新建时默认 `inactive`；该模块已全量上线（含 H5 链路迁移、数据迁移），但开关未打开。

**完成标准**: 验证后打开开关，或按灰度计划推进并记录

---

### TODO-SCRM-007: 商品/课程无评价能力（审计 M2）

**优先级**: 低（需产品确认）

**仓库**: scrm-platform + multi_tenant_saas

**内容**: 评价仅覆盖活动域（activity_evaluations），Course/Product 无 evaluation/review 端点与入口。若产品要求商品/课程评价，需按 entity_type/entity_id 多态 reviews 设计补建。

**完成标准**: 按多态 reviews 设计补建，或产品确认不做并记录

---

### TODO-SCRM-008: 存量代码铁律合规扫描（流程性治理）

**优先级**: 高（防复发）

**仓库**: multi_tenant_saas + scrm-platform

**进度（2026-08-23）**: ✅ uniqid/UUID 主键扫描已完成并清理 4 处（scrm b5b19b8，清单见归档）；剩余：customer 身份误用扫描、大小写路径扫描、「需求→实现」验收映射检查。

**内容**: id-model/identity-model/scrm-architecture 铁律 2026-08-20 才版本化，7 月 AI 高速铺量期（单次提交 2784 行）代码未做合规扫描；已发现 SopService uniqid（已修）、历史 customer_id 32 表误用（已迁移）。同时补「需求→实现」验收映射检查，防设计项静默消失（如侧边栏/自动拉群）。

**完成标准**: 全仓扫描（customer 身份误用、大小写路径）输出清理清单；需求文档增加可勾选验收清单

---

## 已完成归档

- ✅ 2026-08-23 企微开放能力盘点（原 TODO-API-001）：产出 `scrm-platform/docs/2026-08-23-wecom-open-capability-inventory.md`——SDK 24 方法 × 官方端点 × 业务落地对照表；运营动作矩阵覆盖群发/接龙/入群/消息确认四项；自动化边界 9 条（接龙无 API/禁言无 API/群公告受限等）；缺口清单按方向二优先级排序（template_card 回调 P0/联系我活码 P1/朋友圈 P2）。结论：API-002 最小可行路径 = 复用企业群发员工确认流 + 补 template_card 卡片回调。
- ✅ 2026-08-23 H5 SEO/GEO M1 SPA 基础 SEO 化（原 TODO-SEO-001，front 359ba3d+a9b2e4d）：history 路由 + 旧 hash 链接重定向 + 入口 description/OG/JSON-LD + useSeoMeta 接 6 页（canonical 自指）；实测发现 uni-app 构建清空 title，新增 `scripts/patch-h5-seo.mjs` 构建后回填（已验证产物元数据齐全）。同步修复活动域死页：campaign 页重建为活动中心列表页（迁 Activity API）、首页推荐接真实列表、删除 410 端点函数。待办：产物部署 + 真机验证（刷新不 404/旧链跳转）。
- ✅ 2026-08-23 铁律存量清理：uniqid 自造 ID 扫描与修复（原 TODO-SCRM-004 + SCRM-008 uniqid 部分，scrm b5b19b8）。修复 4 处：SopService(sop_id)/AutoWelcomeService(rule_id)/KeywordReplyService(rule_id)/BroadcastService(task_id) 均改 `IdGeneratorContract::generate()`。扫描定性清单：① 已修 4 处（Cache 记录标识）；② 非违规保留：PayoutOrder 打款流水号（业务单号）、Distributor 邀请码、AgentService session_id（会话分组字段非主键）、Customer 占位邮箱/随机密码、MaterialShare 分享令牌；③ 框架侧 5 处为签名 nonce/临时文件名/沙箱名，非主键。测试 71 passed。
- ✅ 2026-08-23 console Dashboard 企微运营通栏卡片上线（原 TODO-WECOM-002）：生产已含 `WecomOnboardCard` + 面板引导 watch `immediate` 修复（框架 07883b55），entry→卡片/ConsoleLayout→AiAssistant 引用链完整；点击卡片可打开小秘书侧边栏并自动发送引导提示词。
- ✅ 2026-08-23 scrm 文档入库（原 TODO-WECOM-004）：`docs/2026-08-21-h5-seo-geo-plan.md`（9c70580）+ `docs/2026-08-22-ai-chained-scenarios.md`（f353954）已入库，todo.md 链接不悬空。
- ✅ 2026-07-31 项目大脑 Phase 0-3 全量上线 + E2E 场景 0-3 验收（L2 确认门 / capability-map / 摘要注入 / thread_review / AssetProbe / health-check）
- ✅ 2026-07-31 tool_calls 落库格式 400 修复（normalizeToolCalls + reconcileToolCallPairs 配对裁剪，框架 5323880）
- ✅ 2026-07-31 锚点预检修复（collectRequiredAnchors 一次性预检 + missing_anchors 结构化返回 + draft 单锚点纪律，框架 5e8b2ff）
