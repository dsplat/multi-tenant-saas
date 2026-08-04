# Commerce 模块技术方案（框架层）

> **文档性质**: 详细技术方案（已全部实施，Phase 1/2/3 均已落地）
> **创建日期**: 2026-08-03
> **最后更新**: 2026-08-04
> **前置文档**: `docs/commerce-sku.md`（抽象分析）、`docs/tenant-commerce-plan.md`（需求）
> **实施状态**: ✅ Phase 1（消费类闭环）✅ Phase 2（供给类）✅ Phase 3（内容库）

---

## 一、层归属结论：全部在框架层

**结论：是，Commerce 是框架层能力。** 依据：

| 判定维度 | 结论 |
|---|---|
| 服务对象 | 平台→租户的商业基础设施，**所有下游项目**（scrm 及未来项目）共用，非单项目业务 |
| 铁律对照 | 「框架已实现的能力，项目继承委托而非重复建表」——若放 scrm，未来第二个下游项目必重建平行实现 |
| 依赖方向 | Commerce 依赖的 Billing/ModuleManager/Subscription/CreditAccount 全在框架层，放项目层会造成反向依赖 |
| 身份模型 | 交易主体为租户实体，操作者为 Operator，纯框架域概念 |

**项目层（scrm）只承接**：Layer B（内容展示链路、实物履约/售后流转）+ 通过框架契约注册项目特定的供给落地实现（见第六节）。

---

## 二、复用盘点（杜绝重复造轮子）

每个实现点的既有基础审计——**能复用的绝不新建，能扩展的绝不重写**：

| 实现点 | 既有基础 | 处置 |
|---|---|---|
| 支付下单/验签/回调 | `PayService`（yansongda/pay 集成，wechat/alipay 下单+回调验签）+ `config/pay.php` 平台默认商户配置 | **扩展**：PayService 增加平台商户维度（现仅租户级） |
| 支付单记录 | `PaymentOrder` 模型 | **复用**：作为 commerce_order 的支付单（1:1，已决策） |
| 退款 | `RefundService`（含退款回调） | **复用**（仅模块/内容等可退类型走此通道；积分包禁止退款已决策） |
| 订阅生命周期 | `SubscriptionService` + `ProcessSubscriptions` 定时任务 | **复用**：PlanHandler 委托 SubscriptionService |
| 模块开关 | `ModuleManager::enableForTenant/disableForTenant` + `tenant_modules` | **复用**：ModuleHandler 履约调开关；权益另立表（已决策） |
| 套餐模块开通 | `ModuleManager::provisionTenantModules` | **复用**：PlanHandler 履约时调用 |
| 积分账户 | `CreditAccount::recharge`（充值/赠送分账字段已备） | **复用**：CreditPackHandler 直接调用 |
| 积分过期 | `ProcessCreditExpiry` 命令 | **复用**：按批次过期扩展 |
| 支付成功通知 | `NotificationService::notifyPaymentSuccess` | **复用** |
| 积分低额预警 | `NotificationService::notifyCreditLow` + `CreditLowNotification` | **复用** |
| 事件分发 | `EventBusService`（事件总线，异步派发） | **复用**：履约结果事件 |
| 审计 | `AuditService` | **复用**：订单/履约/权益变更留痕 |
| Handler 注册模式 | `ToolRegistry`（AI 模块工具注册表） | **参照**：CommerceHandlerRegistry 采用同款注册模式（不自创模式） |
| SKU/订单/授权/模块权益 | 无 | **新建**：Commerce 模块核心 |
| 平台内容库 | 无 | **新建**（P3 阶段，可与 Commerce 同期或滞后） |

**唯一真正的支付改动**：`PayService` 现从 `tenant_settings(group=payment)` 读商户配置（租户收自己的钱）；Commerce 是**平台收租户的钱**，走 `config/pay.php` 的 `wechat.default`/`alipay.default`（env 注入的平台商户号，已预留未使用）。扩展方式：`PayService` 增加 `createPlatformPayInstance(driver)` 与 `handlePlatformCallback()`，与租户级方法并列，不动既有租户链路。

---

## 三、表结构设计（新建 8 张表，P1/P2 共 5 张 + P3 内容库 3 张）

均为框架层迁移，落在 `src/Modules/Commerce/Database/migrations/`。

### 3.1 commerce_skus（平台商品）

```
sku_id (bigint PK, IdGenerator), name, type (plan|module|credit_pack|content_pack|mall_supply),
role (consumer|supply),                      -- 第一级分类：消费者/代理人（已决策）
lifecycle (subscription|one_time|consumable|grant),
fulfill_handler (string),                    -- 策略标识，路由到 Handler
price (decimal), billing_cycle (nullable: monthly|yearly),
payload json,                                -- 差异化参数（模块名/积分面额/内容包ID/SKU清单）
refundable (bool, credit_pack 恒 false),     -- 禁止退款已决策
status (draft|active|retired), sort_order, timestamps
```

无 tenant_id（平台级），**不用** BelongsToTenant。

### 3.2 commerce_orders + commerce_order_items（租户购买）

```
commerce_orders:
order_id PK, order_no unique, tenant_id, sku 汇总金额 amount,
status (pending|paid|fulfilled|partial_failed|cancelled|refunded),
payment_order_id (nullable, 关联 PaymentOrder, 1:1), paid_at, operator_id, timestamps
UNIQUE INDEX (tenant_id, order_no)

commerce_order_items:
item_id PK, order_id FK, sku_id FK, qty, unit_price,
fulfill_status (pending|fulfilled|failed|revoked), fulfill_at, retry_count,
payload_snapshot json,                       -- 下单时 SKU payload 快照，防 SKU 后续变更影响履约
timestamps
```

携带 tenant_id + BelongsToTenant（订单是租户数据）。

### 3.3 supply_grants（供给授权，3/4 共用，已决策）

```
grant_id PK, tenant_id, sku_id, source_order_id,
status (active|suspended|expired|revoked),
valid_from, valid_until (nullable=永久),
settlement json,                             -- 供货价/分成比例等结算参数
instance_payload json,                       -- 履约产物引用（content_id / points_product_id 等）
timestamps
INDEX (sku_id, status), INDEX (tenant_id, status)
```

### 3.4 module_entitlements（模块权益，已决策独立表）

```
entitlement_id PK, tenant_id, module_name,
source (plan|purchase),                      -- 套餐赠送 vs 单独购买
source_order_id nullable, valid_from, valid_until (nullable=买断),
status (active|expired|revoked), timestamps
UNIQUE INDEX (tenant_id, module_name, source_order_id)
```

`tenant_modules` 零改动，保持纯开关语义。可用性判定 = 权益 active **且** 开关 enabled。

---

## 四、履约架构

### 4.1 Handler 契约与注册

```php
namespace MultiTenantSaas\Contracts;

interface CommerceFulfillmentHandler
{
    public function fulfill(CommerceOrderItem $item): void;   // 正向履约
    public function revoke(CommerceOrderItem $item): void;    // 撤销/退款回收
}
```

`CommerceHandlerRegistry`：参照 `ToolRegistry` 注册模式，按 `commerce_skus.fulfill_handler` 字符串路由。框架内置 3 个消费类 Handler；供给类的**实例落地**通过 `SupplyProvisionerContract` 由项目层注册（见第六节）。

### 4.2 五个 Handler 的接线（全部委托既有服务，不重写业务）

| Handler | 归属 | fulfill 动作（委托链） |
|---|---|---|
| PlanHandler | 框架内置 | `SubscriptionService::subscribe()` → `ModuleManager::provisionTenantModules()` → 写配额 |
| ModuleHandler | 框架内置 | 写 `module_entitlements` → `ModuleManager::enableForTenant()` |
| CreditPackHandler | 框架内置 | `CreditAccount::recharge()`（充值额/赠送额按 payload 分账） |
| ContentHandler | 框架调度 + 项目落地 | 写 `supply_grants` → 调项目注册的 `SupplyProvisionerContract::provisionContent()` |
| MallSupplyHandler | 框架调度 + 项目落地 | 写 `supply_grants` → 调项目注册的 `SupplyProvisionerContract::provisionMallSku()`（scrm 实现：写 `points_products`，`source=platform`） |

### 4.3 下单-支付-履约时序

```
Console 下单 → CommerceOrderService::placeOrder()
  → 生成 commerce_order(pending) + items(payload 快照)
  → PayService::createPlatformPayInstance() 拉起支付（平台商户，config/pay.php default）
  → 写 PaymentOrder(关联) → 返回支付参数

支付平台回调 → /api/v1/commerce/pay/callback（无认证，验签）
  → PayService::handlePlatformCallback() 验签
  → 幂等检查（payment_order.status 已 paid 直接返回成功）
  → order.status=paid → 逐项履约：
      foreach item: Handler::fulfill()（单项事务）
        成功 → fulfill_status=fulfilled
        失败 → fulfill_status=failed + retry_count+1，不阻塞其他 item
  → 全部成功 → order.status=fulfilled
  → EventBusService 派发 commerce.order.fulfilled 事件
  → NotificationService::notifyPaymentSuccess()

补偿：ProcessCommerceRetry 定时任务扫 fulfill_status=failed 且 retry_count < 3 重试；
超限进 admin 后台人工处理队列。
```

### 4.4 到期与回收（复用既有定时任务模式）

| 任务 | 模式参照 | 动作 |
|---|---|---|
| `ProcessCommerceGrants`（新命令） | `ProcessSubscriptions` | 扫 supply_grants/module_entitlements 到期 → Handler::expire()：grant 置 expired → 联动项目侧实例下架；entitlement 置 expired → `ModuleManager::disableForTenant()` |
| 积分批次过期 | `ProcessCreditExpiry` | 复用，扩展按充值批次过期 |
| 平台下架联动 | EventBusService | admin 下架 SKU → 事件驱动该 SKU 全部 active grants 失效 |

---

## 五、权益/开关/配置三层的落地校验

`ModuleManager::isEnabledForTenant()` 扩展一处判定：开关 enabled **且** 存在 active 权益（无权益记录的存量模块视为系统授予，向后兼容）。租户自关开关不触发权益变化；到期回收只改权益并联动关开关。

---

## 六、框架-项目分工（scrm 侧最小改动）

| 事项 | 归属 | 说明 |
|---|---|---|
| Commerce 全模块（SKU/订单/履约/授权/权益） | 框架 | `src/Modules/Commerce/`，自包含范式（含 Routes、migrations、admin/console 前端资源） |
| 平台级支付扩展 | 框架 | PayService 平台商户方法 |
| 内容库 | 框架（P3） | 平台级内容表，展示除外 |
| SupplyProvisionerContract 实现 | **scrm** | MallSupplyProvisioner 写 `points_products`（source=platform/source=self 双来源字段）；ContentProvisioner 建租户内容实例；在 ScrmModuleServiceProvider 注册 |
| Layer B：兑换/展示/实物履约/售后 | scrm | 兑换链路已有（Membership），仅补 source 字段与实物履约流转 |

scrm 遵循部署规则：框架变更 → composer update dsplat/* → 部署；console 前端变更单独 rsync。

---

## 七、测试策略

本项目 tests/ 扁平结构。新建：

| 测试文件 | 覆盖 |
|---|---|
| `tests/CommerceOrderServiceTest.php` | 下单、幂等、订单状态机 |
| `tests/CommerceFulfillmentTest.php` | 五个 Handler 履约/撤销/到期（mock 支付） |
| `tests/ModuleEntitlementTest.php` | 权益/开关/配置三层判定、到期回收联动 |
| `tests/SupplyGrantTest.php` | 授权状态机、下架联动、过期 |

执行规则（testing.md）：单文件 `php artisan test tests/Commerce*Test.php`，不跑全量；新增 `src/Modules/Commerce/` 属新模块，不触发基础设施全量条件。

---

## 八、实施阶段

| 阶段 | 内容 | 依赖 | 状态 |
|---|---|---|---|
| **Phase 1**（消费类闭环） | 表结构 + CommerceOrderService + PayService 平台扩展 + CreditPackHandler + ModuleHandler + PlanHandler + 回调履约链 + 补偿任务 | 无外部依赖 | ✅ 已完成 |
| **Phase 2**（供给类） | supply_grants + ContentPackHandler/MallSupplyHandler 调度 + SupplyProvisionerContract + scrm 侧 Provisioner 实现 | Phase 1 + scrm `points_products` 加 source 字段 | ✅ 已完成 |
| **Phase 3**（内容库） | 平台内容库 + 内容包 SKU + 展示链路（scrm） | Phase 2 | ✅ 已完成 |

Phase 1 即可上线「AI 积分购买 + 模块加购 + 套餐购买」三条消费链路，与 P0/P1 需求对齐。

### 8.1 实施落地清单

**新建文件**（`src/Modules/Commerce/`）：

| 类别 | 文件 |
|---|---|
| ServiceProvider | `CommerceServiceProvider.php` |
| 模型 | `CommerceSku.php`, `CommerceOrder.php`, `CommerceOrderItem.php`, `SupplyGrant.php`, `ModuleEntitlement.php`, `PlatformContent.php`, `PlatformContentPack.php`, `PlatformContentPackItem.php` |
| 服务 | `CommerceOrderService.php`, `CommerceFulfillmentService.php`, `CommerceHandlerRegistry.php`, `SupplyProvisionerRegistry.php`, `PlatformContentLibraryService.php` |
| Handler | `PlanFulfillmentHandler.php`, `ModuleFulfillmentHandler.php`, `CreditPackFulfillmentHandler.php`, `AbstractSupplyFulfillmentHandler.php`, `ContentPackFulfillmentHandler.php`, `MallSupplyFulfillmentHandler.php` |
| 控制器（Console） | `CommerceCatalogController.php`, `CommerceOrderController.php`, `CommerceSupplyGrantController.php`, `CommercePayCallbackController.php` |
| 控制器（Admin） | `CommerceAdminController.php`, `CommerceContentAdminController.php` |
| 命令 | `ProcessCommerceRetry.php` |
| 迁移 | `2026_08_03_000001_commerce_module.php`（SKU/订单/订单项/权益）, `2026_08_03_000002_commerce_supply_grants.php`, `2026_08_03_000003_commerce_content_library.php` |
| 路由 | `Routes/api.php`（Console 端）, `Routes/admin.php`（Admin 端）, `Routes/public.php`（支付回调） |

**新增框架契约**：

| 契约 | 位置 | 说明 |
|---|---|---|
| `CommerceFulfillmentHandler` | `src/Contracts/` | 履约 Handler 统一接口（fulfill / revoke） |
| `SupplyProvisionerContract` | `src/Contracts/` | 供给落地器契约（provisionContent / provisionMallSku / deprovision） |

**PayService 平台扩展**（`src/Modules/Billing/Services/PayService.php`）：

- `platformWechatH5(float $amount, string $orderNo): array` — 平台商户微信 H5 预下单
- `platformAlipayWeb(float $amount, string $orderNo): string` — 平台商户支付宝 PC 下单
- `platformAlipayWap(float $amount, string $orderNo): string` — 平台商户支付宝 WAP 下单
- `handlePlatformCallback(string $driver, Request $request): array` — 平台商户回调验签

---

## 九、分销产品的支付/结算路径

供给类（role=supply）SKU 的资金流分两段，**与消费类实时支付不同**：

### 9.1 用户兑换段（Layer B，无人民币支付）

用户用租户积分兑换（复用 Membership 兑换链路）；若租户商城支持现金/混合支付，走**租户自己的商户配置**（现有租户级 PayService，钱进租户商户号），平台不经手该笔资金。

### 9.2 平台⇄租户结算段（Layer A，三种结算模式，按 SKU 配置）

| 模式 | 资金流 | 适用 |
|---|---|---|
| **预付买断** | 选入时批量下单 → 实时支付（走 Commerce 平台商户支付链，同积分包）→ 获得库存额度 | 首批主推，风险最低 |
| **账期月结** | 先售后结：按周期汇总已兑换量 → 生成结算单（settlement_statements）→ 平台收款（积分账户扣减/线下对公） | 大客户 |
| **收益分成** | 按实际兑换/销售额约定比例分成，结算单体现 | 内容类/高毛利 SKU |

结算单新建 `settlement_statements` 表（租户/周期/明细引用 supply_grants/兑换记录/金额/状态）；逾期不结联动 suspend supply_grants（停供不停兑：已上架商品冻结新兑换）。

---

## 十、实物履约：地址/物流跟踪/平台-租户对接

### 10.1 层归属

| 能力 | 层 | 说明 |
|---|---|---|
| 用户地址簿 | 项目层（scrm） | `user_addresses`（租户隔离），兑换主体为 user_id |
| 兑换/发货单 | 项目层 | 兑换单 + `delivery_orders`（承运商/单号/状态） |
| 物流查询服务抽象 | **框架层** | `LogisticsTrackingService` + driver 契约（kuaidi100/kuaidiniao/自定义），租户配置存 tenant_settings(group=logistics) |

### 10.2 平台产品⇄租户商品对接（一件代发链路）

```
用户兑换(source=platform 实物 SKU)
  → 租户商城生成兑换单（携带收货地址快照）
  → 事件自动流转发货请求至平台（supply_grants 关联）
  → 平台侧发货（WMS/人工）回填承运商+单号
  → 单号回流租户 delivery_orders → 订阅物流轨迹推送 → 用户可见
```

租户自履约 SKU 则完全在租户侧闭环，平台不感知。

### 10.3 物流查询的两条接入路线与成本

| 路线 | 配置 | 成本承担 |
|---|---|---|
| 租户自接 | 租户自带供应商 key（tenant_settings） | 租户自担 |
| 平台统一代理 | 平台持主订阅，租户调平台接口 | **有成本**（供应商按量计费）：平台可吸收/经 CostService 成本分摊透传/包装为「物流查询」付费加购模块（与模块商品化打通） |

**成本控制关键**：优先用供应商的**订阅推送模式**（单号订阅后轨迹变更回调推送），而非用户点击时实时拉取——前者按订阅单计费且缓存命中高，后者每次点击都计费。

---

## 十一、物流与地址服务选型（2026-08 调研）

### 11.1 物流轨迹查询供应商

| 供应商 | 能力 | 计费 | 定位 |
|---|---|---|---|
| **快递100**（api.kuaidi100.com） | 千家级承运商，生态最全，文档成熟，另有智能地址解析/电子面单等周边产品 | 按量阶梯计费或包年套餐 | **主推**（一家打通查询+地址+面单） |
| **快递鸟**（kdniao.com） | 2500+ 承运商，宣称 99.97% SLA，异常件识别较强，有免费额度 | 按量/套餐 | 备选/比价 |
| 17TRACK | 国际件轨迹 | 按量 | 有跨境需求时补充 |

Driver 契约设计保证供应商可切换，不绑死单一厂商。注：以上 SLA/覆盖宣称来自厂商营销口径，签约前需实测验证。

### 11.2 智能地址管理

| 层 | 方案 | 成本 |
|---|---|---|
| 后端解析（整段文本→姓名/电话/省市区结构化） | 快递100智能地址解析 API（与查询同供应商，支持纠错补全）；备选：腾讯位置服务/高德地址服务/阿里云地址解析 | 按量，低价 |
| 前端交互 | 无需专用 SDK：开源行政区划数据（pcas-code/element-china-area-data，本地免费）+ Vant AddressEdit / Element Plus Cascader；「粘贴智能识别」=输入框+调后端解析 API | 免费 |
| 降级链（AI 可选性） | 供应商 API 主 → 框架 AiTextService 备（fail-open）→ 手动结构化录入兜底 | — |

地址数据存 `user_addresses` 时对电话字段加密存储（同 TenantSettingService 加密模式）。

---

## 十二、分佣设计

分佣分两个层面，层级与资金路径完全不同：

### 12.1 Layer A：平台⇄租户（已由结算模式覆盖）

供货价/收益分成即此层分佣，载体为 `settlement_statements`，不重复建设。

### 12.2 Layer B：租户⇄推广者（用户级分佣，新建）

| 要素 | 设计 |
|---|---|
| 层级合规 | **最多两级**（推荐人+上级），杜绝三级及以上（涉传红线）；层级数租户可配（1/2），硬上限 2 |
| 佣金载体 | 默认**积分/商城余额**（租户体系内可用），现金提现为可选高风险模式（见十三节二清风险） |
| 冻结期 | 佣金入账后冻结至售后期结束（实物=确认收货+N 天；虚拟=核销后）才可动用，防套佣 |
| 防作弊 | 自购自推、同设备/同地址簇、退款套佣识别；参照框架抽奖/投票模块反作弊既有模式 |
| 归属 | 框架提供 `CommissionService` 抽象（佣金规则/账本/冻结/状态机）+ 合规护栏；租户侧推广关系与玩法配置在项目层 |

新增表：`commission_rules`（租户/层级/比例/适用 SKU 范围）、`commission_ledger`（账本：冻结/可用/已动用）。

---

## 十三、税务与发票

### 13.1 开票义务矩阵（谁是销售主体谁开票）

| 交易 | 销售主体 | 开票义务 | 平台动作 |
|---|---|---|---|
| 平台→租户（套餐/模块/积分包/供应） | 平台 | 平台向租户开票 | 复用 `InvoiceService`；CN 税率：软件/技术服务 6%、实物供应 13%（`TaxService` 多档已支持） |
| 租户→用户（现金购买） | 租户 | 租户向用户开票 | 平台不经手 |
| 用户积分兑换 | 非销售行为 | 积分发放时已随原销售行为纳税，兑换不再开票 | — |
| 分佣支出 | 平台/租户为支付方 | 企业推广者：对方开票；个人：代扣（见 13.3） | — |

### 13.2 税额计算复用

`TaxService::calculateTax(region, amount, productType)` 直接接入 Commerce：`commerce_skus.payload` 增加 `product_type`（service/goods/…）与 `region` 语义，下单时计税入快照；发票经 `InvoiceService` 生成（状态机/行锁防并发已备）。多币种沿用 Invoice 的 currency 字段（Stripe/PayPal/银联驱动已支持国际卡）。

### 13.3 税务代扣（个人分佣）

| 模式 | 机制 | 适用 |
|---|---|---|
| **累计预扣法自管** | 平台作为扣缴义务人，按劳务报酬预扣；新建 `WithholdingService`：月累计收入→预扣率→代扣记录→申报清单导出 | 佣金体量小 |
| **灵活用工平台委托代征** | 对接持牌灵活用工平台（佣金经其下发，完税凭证回流），平台不直接处理个税 | **体量上来后的主推**，合规风险外包 |

`TaxRule` 表扩展代扣规则行（region=CN, type=withholding）；个税免征额/预扣率等政策参数全部配置化，不硬编码（政策频变）。身份模型：推广者是 User 实体，代扣记录挂 user_id。

---

## 十四、消费端混合购买（积分+现金）

| 要素 | 设计 |
|---|---|
| 订单模型 | 租户商城订单行增加拆分字段：`points_part`（积分数）+ `cash_part`（金额）+ 各自状态 |
| 抵扣规则 | 每 SKU 可配最高积分抵扣比例（0~100%）；租户全局默认 + SKU 级覆盖 |
| 执行顺序 | **积分先冻结→现金支付成功→积分实扣**；现金支付超时/失败→积分解冻（防积分被白锁） |
| 退款 | 原路各退：现金走 RefundService，积分回退 Membership（已过期的积分按租户策略补发或不补） |
| 开票 | 仅现金部分可开票；积分部分不开票 |
| 归属 | 项目层（scrm），框架不感知；框架提供 Membership 积分冻结/实扣/解冻原语（Membership 需补冻结能力） |

---

## 十五、你没提到但必须设计的盲点

### 15.1 二清合规（最高优先级）

平台代收用户资金再结算给租户 = 无证经营支付业务（二清），监管红线。**当前设计已天然规避**：用户现金支付进租户自己的商户号（租户级 PayService），平台只碰租户→平台的钱（平台商户号收）。铁律：**永远不让用户资金过平台账户**；用户级分佣只发积分/商城余额正是为此。

### 15.2 其他盲点清单

| # | 盲点 | 处置 |
|---|---|---|
| 1 | 积分负债属性：预收积分/充值构成递延收入 | 财务报告经 FinancialRecord 区分「递延负债/已确认收入」，消耗时才结转 |
| 2 | 改价保护：平台 SKU 调价影响存量 | `payload_snapshot` 已保订单；supply_grants 结算价锁定签约时，调价只影响新单 |
| 3 | 超卖防护 | 共享池原子扣减（`UPDATE ... WHERE stock >= qty` 影响行数判定）；兑换单幂等键防重复提交 |
| 4 | 虚拟商品卡码池 | 卡码类 SKU 需 `card_code_pool`（平台导入、租户侧核销、防重复发放）——P2 阶段必需，勿到上线才补 |
| 5 | 消费者权益：实物 7 天无理由 | 售后状态机预留；平台代发 SKU 的退换货反向联动平台库存与结算单（冲销行） |
| 6 | 结算争议/对账 | 结算单支持逐项争议标记；平台-租户双方可导出对账单（CSV），对平后才可确认 |
| 7 | 数据合规：地址/电话 PII | 电话加密存储（已定）；GDPR 导出/擦除清单需把 user_addresses/commission_ledger 纳入（框架已有 14 类导出，扩 2 类） |
| 8 | 平台商户资质 | config/pay.php 平台商户号需真实开通微信/支付宝商户能力（当前 env 位预留未用），属上线前置条件 |

---

## 十六、本轮新增表汇总（叠加第三节）

| 表 | 所属 | 阶段 |
|---|---|---|
| `settlement_statements` | 框架 Commerce | P2（供给类） |
| `commission_rules` / `commission_ledger` | 框架 Commission | P2 |
| `withholding_records`（代扣记录） | 框架 Billing/Tax 扩展 | 佣金提现开启时 |
| `user_addresses` / `delivery_orders` | 项目层 scrm | P2 |
| `card_code_pool` | 框架 Commerce | P2（卡码类 SKU 前必需） |
