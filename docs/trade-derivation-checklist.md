# 新商城派生清单（交易域 5 模块）

> 适用：基于 multi_tenant_saas 框架快速派生一个独立多租户商城。
> 已验证：2026-08 mall-demo 本地派生冒烟（建租户/建商品 SKU/现金下单/积分渠道未注册优雅降级/发货登记全部通过）。

## 1. 模块依赖

```
Product（无依赖） ← Pay（依赖 billing） ← Order（依赖 product, pay, event）
                                          ↑
                    Course（依赖 product, order）   Logistics（依赖 order）
```

composer 引入（split 包）：

```bash
composer require dsplat/multi-tenant-saas-module-product \
                 dsplat/multi-tenant-saas-module-pay \
                 dsplat/multi-tenant-saas-module-order \
                 dsplat/multi-tenant-saas-module-course \
                 dsplat/multi-tenant-saas-module-logistics
```

模块随 composer 安装自动注册（composer.json `extra.saas` 元数据 + ModuleServiceProvider 发现），无需手工注册。

## 2. 数据库

- 全新项目：直接 `php artisan migrate`（47+ 迁移，含 products/product_skus/orders/order_items/sales_configs/courses/shipments 等）。
- 接管既有库（表已存在）：参考 scrm-platform `scripts/adopt_trade_migrations.php` —— 把已建表迁移文件名登记进 migrations 表（幂等），新表（如 shipments）真实执行。

## 3. 配置项

| 配置 | 默认 | 说明 |
|---|---|---|
| `<module>.route_prefix`（product/pay/order/course/logistics） | `''` | C 端/Console API 前缀。默认 `/api/v1/<resource>`；scrm 配 `'scrm'` 保生产 URL 零变更 |
| `sales_configs` 表 | 无记录 | 折现/销售配置，Console 销售配置页维护 |

## 4. 项目层钩子（可选，按需注册）

- **虚拟支付渠道**（积分/余额等）：实现 `VirtualPayChannelContract`，Provider boot 中
  `app(VirtualPayChannelRegistry::class)->register(...)`。未注册时积分支付优雅降级
  （`Virtual pay channel [points] is not enabled`，422）。
- **履约处理器**（课程/票务等）：实现 `OrderFulfillmentHandlerContract`，注册进
  `FulfillmentRegistry`。Course 模块内置 CourseFulfillmentHandler 自动注册。
- **订单事件监听**：`OrderPaid` / `OrderRefunded`（计佣、埋点、发券等业务留项目层）。
- **课程学完奖励**：实现 `CourseCompletionRewardContract`（默认 Null，无副作用）。

## 5. Console 前端

- 项目侧 `resources/js/console/` 扩展框架 Console SPA（参考 scrm-platform）：
  vite.config 用 `createConsoleConfig({ projectRoot })` 或自建配置；`@/` 指向项目 resources/js 时
  **必须补精确别名** `@/shared/http` → 框架 `resources/js/console/shared/http.ts`。
- 交易域 API 前缀：main.ts 最先 import 一个设置 `window.__TRADE_API_PREFIX__` 的文件
  （scrm 设 `'/scrm'`；新商城不设即走默认）。
- 模块 Console 页面随包分发（`vendor/dsplat/*/resources/console/`），module-loader 构建时 glob 自动发现，
  `npm run build` 后 rsync `public/console/` 即可。

## 6. H5 前端

- monorepo 引入共享包 `packages/h5-commerce`（源在 scrm-platform-front，可复制）：
  api（商品/订单/课程/积分兑换）+ 组件（NavBar/商品卡片/SKU 选择器）+ 整页视图（商城/课程/积分兑换）。
- 宿主接入两步：
  1. `main.ts`：`configureH5Commerce({ apiPrefix: '<前缀>' })`（默认空前缀可省略）
  2. `pages.json` 注册薄壳页面，页面仅 import 包内视图组件（生命周期留宿主，`refreshTick` 驱动刷新）

## 7. 派生验证清单

- [ ] migrate 通过（全新库）
- [ ] 建租户、建商品/SKU
- [ ] 现金下单 → 确认支付 → 发货登记
- [ ] 积分支付在未注册渠道时返回 422 优雅降级
- [ ] `route:list` 确认默认前缀 `/api/v1/products|orders|…`
- [ ] Console 构建产物含 ProductManagement/OrderManagement/CourseManagement/SalesConfig/ShipmentManagement

## 参考实现

- scrm-platform：生产级接入样例（前缀 'scrm'、PointsVirtualPayChannel、OrderPaid 计佣/埋点监听、迁移接管脚本）
- 冒烟脚本样例：mall-demo `smoke_derivation.php`（建租户→商品 SKU→下单→降级→发货）
