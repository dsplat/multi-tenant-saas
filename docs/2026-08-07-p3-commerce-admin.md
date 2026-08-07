# P3: Admin 菜单动态化 + 商业供给闭环（SKU 商品池 / 供给授权）

**会话时间**: 2026-08-07（P2 后续会话）  
**范围**: 框架 multi_tenant_saas → neihang.com 生产部署（admin.neihang.com）  
**状态**: ✅ 已完成并验证  
**前置**: [admin 功能缺口 Review](./2026-08-07-admin-gap-review.md)（B/C/M 缺口编号体系）

---

## 目标

落实缺口 Review 的 P3 优先级（纯前端打通商业闭环 + 根治菜单漏配）：

1. **M3 菜单入口缺失**（已复发 3 次）→ 菜单动态化机制
2. **M1 SKU 商品池零页面**（用户痛点「平台没有商品池可供租户销售」的直接根因）→ SkuPool 页
3. **M2 供给授权零页面** → SupplyGrants 页

**无牌照前提**（本会话决策）：平台默认无支付牌照，不支持代收/二清；
自营直销不受影响；供货场景采用「预存款 + 划拨 + 扣款下发」模型（Phase 4 实施，见计划文档）。
SupplyGrants 页的 settlement 字段按「仅记账口径」展示，不做资金执行。

---

## 实施清单

### P3.1 菜单动态化机制 ✅

**契约**：模块在路由 `meta.menu = { section, label, perm? }` 声明菜单项；
`AdminLayout`（element-plus / bootstrap 两版）保留核心分组硬编码，
`onMounted` 调 `collectMenuItemsBySection()` 聚合模块菜单分组追加渲染，仍走 `hasPermission` 过滤。

- [module-loader.ts](../resources/js/admin/module-loader.ts)：
  - 新增 `ModuleMenuItem` 接口与 `collectMenuItemsBySection()`
  - `makeRoute()` 自动发现页支持 `knownPageMenus` 登记表（当前为空表，按需登记）
  - `getAllModuleRoutes()` 加 Promise 缓存（router 与菜单聚合共用，不重复加载模块路由）
- 有自定义 routes.ts 的模块（Commerce/Platform/Notification）直接在其 meta.menu 声明；
  自动发现模块走 `knownPageMenus`（二者不冲突：自定义 routes 模块不走自动发现）
- 分组图标：`SECTION_ICONS` 映射（商业运营 → Goods/cart），未知分组降级 List/module 图标

**效果**：新增模块页面只需在 routes.ts 声明 meta.menu，菜单自动出现，
不再需要同步改两版 AdminLayout 硬编码（根治 P2 遗留的菜单漏配模式）。

### P3.2 SKU 商品池页 ✅

新页面 `src/Modules/Commerce/resources/admin/ui/element-plus/views/SkuPool.vue`
（Commerce 模块无 bootstrap 版视图先例，沿用 element-plus only）：

- 列表：五类型（plan/module/credit_pack/content_pack/mall_supply，对齐 `CommerceSku` 常量）、
  role（consumer/supply）、status（draft/active/retired）三维筛选
- 编辑弹窗：CrudForm schema 驱动；payload JSON 文本域按 type 给出示例提示
  （credit_pack → `{amount, gift}` 等）；提交前 JSON.parse 校验
- 下架 = DELETE（`skuRetire`），二次确认文案提示「供给授权将同步失效」
- 后端配套：`CommerceAdminController::skuIndex()` 补 type 过滤（原仅 role/status）

### P3.3 供给授权页 ✅

新页面 `SupplyGrants.vue`，对接 `/admin/commerce/supply-grants`（tenant/status 过滤）+ suspend/resume：

- 表格：授权ID、租户、SKU（with sku 关联名）、来源订单、状态、有效期、settlement 摘要
- settlement 摘要仅解析 mode/supply_price/share_ratio 展示（记账口径）
- 停供（active→suspended，确认弹窗提示「停供不停兑」）/ 恢复（suspended→active）

### P3.4 路由与存量漏配登记 ✅

- Commerce `routes.ts`：新增 sku-pool / supply-grants 声明，四个页面全部挂
  meta.menu（section=商业运营）——CommerceOrders / ContentLibrary 由此获得菜单入口（M3）
- Platform `routes.ts`：tenant-applications（团队管理）、apply-field-config（平台配置）补 meta.menu
- sandbox 已在硬编码菜单，不重复登记

### 测试 ✅

- 框架精准测试：`CommerceFulfillmentTest / CommerceOrderServiceTest / CommerceSupplyGrantTest`
  24 passed（70 assertions）
- SPA 构建：element-plus + bootstrap 两版通过，SkuPool/SupplyGrants chunk 产出正常
- routes.ts 的 `.vue` 模块类型报错为存量问题（缺 vue shim，不影响 Vite 构建）

---

## 部署记录

1. 框架提交 `7f4989e` → push → split.yml 连续两次失败：AiStreaming 模块 node_build 的
   `npm ci` 报 E502——其 package-lock.json 的 resolved 全部锁定已故障的 npmmirror 镜像
   （本地 curl 同样 000，非瞬断）。修复：resolved 主机批量替换为 registry.npmjs.org
   （integrity 不变，本地 npm ci 验证通过），提交 `43690e0` 后 split 成功
2. scrm-platform `composer update "dsplat/multi-tenant-saas" "dsplat/multi-tenant-saas-module-*" --with-dependencies` → commit composer.lock（`016ec1b`）
3. `python3 deploy/deploy.py incremental --yes`（composer.lock 变更触发服务器 composer install）
4. SPA rsync：`rsync -avz --delete public/admin/ root@192.168.100.11:/data/app/neihang.com/public/admin/`
5. 超管浏览器端到端验证（admin.neihang.com，hash 备份/临时密码/还原模式）：**7 步全通过**
   - 「商业运营」动态菜单分组出现 4 项（SKU 商品池/供给授权/商业订单/内容库）——验证 meta.menu 机制生效
   - SKU 商品池新建 credit_pack SKU（POST 201）→ 列表出现；类型过滤生效（含负向验证）
   - 供给授权页加载正常；「团队管理」新增「租户应用」动态菜单项可访问
   - 全程无控制台错误；测后测试 SKU 已物理删除、超管 hash 已还原
   - 小瑕疵（后续修）：租户应用页面包屑英文未翻译

---

## 遗留 / 下一步

- **Phase 4 供货结算与预存体系**（规避二清闭环）：supply_prepay 账户（CreditAccount account_type 复用）、
  supply_grants 库存化（allocated/remaining/locked_qty）、履约事务链（锁库存→扣预存→下发→补偿）、
  域名保证金（domain_deposit 独立台账）
- Notification 模块 routes.ts（邮件模板列表）未挂 meta.menu——按需后续登记
- 缺口 Review 中的 P5/P5.5/P6（订阅运营 / AI 消耗透视 / 财务完善）按计划顺序推进
