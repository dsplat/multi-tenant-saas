# 平台-租户商业体：统一 SKU 抽象分析

> **文档性质**: 架构分析（实现方案前置分析，未进入开发）
> **创建日期**: 2026-08-03
> **关联文档**: `docs/tenant-commerce-plan.md`（需求）、`docs/tenant.md`（租户体系）

---

## 一、命题与结论

**命题**：租户开通套餐、开通模块、获取可分销内容、获取积分兑换供应，本质上都是 SKU，只是交付方式不同（系统交付 vs 实物/快递交付）。要求统一抽象 + 差异化实现。

**结论**：命题基本成立，但需要一个关键修正——

> **在「平台→租户」这一层，全部 SKU 都是系统交付，不存在快递交付。**
> 快递只发生在「租户→用户」层（终端用户兑换实物 SKU 时）。
> 混淆这两层会把物流、售后塞进平台订单系统，是模型腐化的起点。

因此抽象的正确切面是**两层交易模型**（见第二节），统一 SKU 抽象作用在 Layer A，差异化实现在履约 Handler 与 Layer B 消费侧展开。

### 1.1 租户双重商业角色（消费者 vs 代理人）

租户对平台同时扮演两个商业角色，两类交易**不混捏**：

| 角色 | 买什么 | 履约作用对象 | SKU 类型 | 账务 |
|---|---|---|---|---|
| **消费者**（自用） | 套餐、模块加购、AI 积分包 | 租户自身（开关/配额/账户余额） | 能力与消耗类 | 消费账（订阅费/充值） |
| **代理人**（分销） | 内容包、商城 SKU 供应 | 租户的下游 User（生成可再分发实例） | 供给类 | 分销账（供货价/分成结算） |

- 这是**商业角色**区分，不是身份区分：认证身份仍只有 Operator/User（身份模型铁律），租户是组织主体，两种角色由同一租户实体承担。
- 工程意义：两类 SKU 的订单、履约 Handler、账务流水、退款策略完全分轨；供给类额外需要结算单与下架联动，消费类不需要。
- `commerce_skus` 增加 `role` 维度（consumer | supply），作为履约路由与账务分轨的第一级分类，`type` 为第二级。

---

## 二、两层交易模型

```
┌─ Layer A：平台 → 租户（B2B 供给交易）────────────────────────┐
│  卖什么：套餐 / 模块加购 / AI积分包 / 内容包 / 商城SKU供应     │
│  交付方式：全部系统交付（支付回调 → 履约Handler自动生效）       │
│  交付物：权益（订阅状态/开关权/账户余额/授权记录/供应实例）     │
│  归属：框架层 Commerce 能力                                    │
└──────────────────────────────────────────────────────────────┘
                            ↓ 租户获得资源后自行经营
┌─ Layer B：租户 → User（B2C 消费经营）────────────────────────┐
│  消费方式：内容展示 / 积分兑换 / 活动参与                      │
│  交付方式：虚拟=系统发放；实物=快递履约（仅此层出现物流）       │
│  归属：下游项目层（scrm Membership/内容展示/履约流转）          │
└──────────────────────────────────────────────────────────────┘
```

**铁律**：Layer A 的订单系统不感知物流；Layer B 的履约不感知平台订单。两层通过「供应实例」（租户侧副本/授权记录）衔接。

---

## 三、问题 3 与 4 是一回事吗？

**获取层同构，消费层异构。**

| 维度 | 3 内容分销 | 4 SKU 供应 | 判定 |
|---|---|---|---|
| 获取动作 | 租户从平台库选入 | 租户从平台库选入 | **同构** |
| 履约产物 | 租户侧授权/副本记录 | 租户侧商品实例（points_products 副本） | **同构** |
| 结算模型 | 免费/积分/分成 | 供货价/账期/分成 | **同构** |
| 生命周期 | 授权期 + 平台下架联动 | 授权期 + 平台下架联动 | **同构** |
| 下游消费 | User 浏览/展示消费 | User 积分兑换 + 履约 + 售后 | **异构** |
| 库存语义 | 无库存（可无限分发） | 有库存（共享池/租户配额） | **异构** |

**工程结论**：3 和 4 应共用同一套「供给授权」抽象（选入 → supply_grants → 租户侧实例），只在两个点位分叉：

1. **履约 Handler 不同**：ContentHandler 建内容授权，MallSupplyHandler 建商品实例 + 库存额度；
2. **Layer B 消费链路不同**：内容走展示链路，SKU 走兑换+履约链路（复用下游 Membership）。

所以：不是"一回事"，但是"同一抽象下的两个 Handler"。

---

## 四、统一 SKU 抽象（Layer A）

### 4.1 核心模型

```
commerce_skus（平台商品表，无 tenant_id）
├── sku_id / name / type（plan | module | credit_pack | content_pack | mall_supply）
├── lifecycle（subscription | one_time | consumable | grant）
├── delivery_mode = system（Layer A 恒为 system）
├── fulfill_handler（策略标识，如 plan / module / credit_pack / content / mall_supply）
├── pricing（售价/周期/阶梯，报价信息不入库，价格可配）
├── payload json（差异化参数：模块标识/积分面额/内容包ID/SKU清单）
└── status（上架/下架）

commerce_orders + commerce_order_items（租户购买）
├── order_no / tenant_id / amount / status
├── items: sku_id / qty / unit_price / fulfill_status（pending|fulfilled|revoked）
└── payment_order_id（关联支付单，复用 PaymentOrder/PayService）

supply_grants（供给授权，3/4 共用的履约产物中间层）
├── tenant_id / sku_id / source_order_id
├── status（active | suspended | expired | revoked）
├── valid_from / valid_until
└── payload json（实例引用：content_id / points_product_id 等）
```

### 4.2 订单-支付-履约三段式

```
下单（commerce_order）→ 支付（PaymentOrder，复用现有驱动）
  → 回调验签（幂等）→ 事件驱动逐项履约（FulfillmentHandler）
  → fulfill_status 落位 → 通知租户
```

一致性要求：回调幂等、履约可重试（补偿队列）、单 item 履约事务化；任一 item 履约失败不阻塞其他 item，进入人工/重试通道。

### 4.3 履约策略：统一接口 + 差异化 Handler

```php
interface CommerceFulfillmentHandler {
    public function fulfill(OrderItem $item): void;    // 正向履约
    public function revoke(OrderItem $item): void;     // 退款/撤销回收
    public function expire(GrantRecord $grant): void;  // 到期处理（订阅/授权类）
}
```

| Handler | 履约动作（差异化） | 复用的现有能力 |
|---|---|---|
| **PlanHandler** | 建/续订阅 → 按套餐刷模块开通（`provisionTenantModules`）→ 写配额 | `SubscriptionService`、`ModuleManager` |
| **ModuleHandler** | 写加购权益（source=purchase, expire_at）→ `enableForTenant` 开开关 | `ModuleManager`、`tenant_modules` 表 |
| **CreditPackHandler** | `CreditAccount::recharge`（充值额/赠送额分账） | `CreditAccount`、`ProcessCreditExpiry` |
| **ContentHandler** | 写 supply_grant → 建租户内容授权/副本 | 新建（依赖内容库建设） |
| **MallSupplyHandler** | 写 supply_grant → 建租户 `points_products` 副本 + 库存额度 | 下游 Membership 表结构已对齐 |

### 4.4 租户商城 SKU 的双来源（已确认）

租户的分销商城/积分商城中，SKU 来源为两条腿：

| 来源 | 进入方式 | 实例特征 |
|---|---|---|
| **平台选入** | 供给类 SKU 履约自动建实例 | `source=platform`，携带平台 SKU 引用，受授权期/下架联动/供货价结算约束 |
| **租户自上传** | 租户自建（现有 `points_products` 创建链路） | `source=self`，完全自治，平台不感知 |

两来源 SKU 在同一商城混合陈列、共用同一条兑换链路；平台侧报表按 source 区分经营口径。

---

## 五、开关与配置：权益 / 开关 / 配置 三层分离

用户诉求「付费后需要开关设置、系统配置相关」的正确落位——**履约不等于拨一个开关**，付费产出的是三层状态：

| 层 | 回答的问题 | 载体 | 谁可变 |
|---|---|---|---|
| **权益层** | 有没有资格（钱付没付、到没到期） | commerce_orders / supply_grants / subscription | 仅交易与到期流程可变 |
| **开关层** | 当前开没开 | `tenant_modules.status`、AI 能力开关 | 租户可自行临时关闭（权益仍在，重开无需再付费） |
| **配置层** | 怎么开的（配额、参数、预算、告警阈值） | `SubscriptionPlan.limits`、tenant_settings、config/ai.php 租户节 | 租户在权益范围内自调 |

差异化规则：

- **回收只作用于权益层**：到期/退款 → 权益失效 → 联动开关层关闭；租户自关开关不影响权益。
- **套餐与加购解耦**：套餐降级/到期，单独加购的模块权益独立存续（source 字段区分套餐赠送 vs 购买）。
- **ModuleHandler 履约时写 `tenant_modules` 需扩展**：现有表无 source/expire_at，需加权益字段或独立 `module_entitlements` 表承载（建议后者，tenant_modules 保持纯开关语义）。

---

## 六、五类 SKU 差异化矩阵

| 维度 | 套餐 | 模块加购 | AI积分包 | 内容包 | SKU供应 |
|---|---|---|---|---|---|
| 生命周期 | 订阅（周期续费） | 订阅或买断 | 消耗型 | 授权期 | 授权期 |
| 履约产物 | 订阅+模块组+配额 | 开关+权益 | 账户余额 | 授权记录 | 商品实例+库存额度 |
| 到期行为 | 降档/关模块/限配额 | 关开关留数据 | 按批次过期 | 授权失效 | 商品下架、未兑换冻结 |
| 退款回收难度 | 中（按比例折算） | 低（关开关） | **高**（已耗不可回收，需冻结策略） | 低（失效授权） | **高**（已兑换履约不可逆） |
| 叠加规则 | 互斥（唯一在订） | 与套餐叠加、独立存续 | 余额累加 | 多包并存 | 多来源并存 |
| Layer B 触达 | 无 | 无 | 无 | 展示消费 | 兑换+履约（含快递） |

**风险锚点**：积分包与 SKU 供应是仅有的两个「回收不可逆型」SKU。已决策：积分包**禁止退款**（下单前明示）；SKU 供应退款不追溯已兑换订单。

---

## 七、实现落点与改造面

| 层 | 动作 |
|---|---|
| 框架层新建 | Commerce 模块：`commerce_skus` / `commerce_orders` / `commerce_order_items` / `supply_grants` + Handler 注册表 + 支付回调履约分发 |
| 框架层扩展 | `tenant_modules` 语义保持纯开关；新增模块权益表；`SubscriptionPlan.features/limits` 纳入 SKU payload 生成 |
| 框架层复用 | `PaymentOrder` + `PayService`（支付单）、`SubscriptionService`（订阅）、`ModuleManager`（开关）、`CreditAccount`（账户） |
| 项目层（scrm） | Layer B 承接：内容展示链路、兑换链路（已有）、实物履约与售后流转 |

---

## 八、已决策清单（2026-08-03 定案）

| # | 决策点 | 结论 |
|---|---|---|
| 1 | 模块权益载体 | **独立 `module_entitlements` 表**：`tenant_modules` 保持纯开关语义，权益（来源/订单/到期）单独承载 |
| 2 | commerce_order 与 PaymentOrder 关系 | **1:1 起步**：一业务订单一支付单，后续有合并支付需求再演进 |
| 3 | supply_grants 是否 3/4 共用 | **共用一张表**：两类供给同表承载，状态机/到期/下架联动/结算一套代码，差异进 payload |
| 4 | SKU 供应库存模型 | **平台共享池**：库存真相唯一，原子扣减防超卖，适配代发模式 |
| 5 | 积分包退款策略 | **禁止退款**：下单前明示，实现最简、无折算争议 |
