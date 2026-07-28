---
module: monitoring
audience: operator
locale: zh
facts_checksum: 2877895f9f91b1b8cc35434cc67e79b2751ccb4d458e137a31a8739623110b5d
generated_by: secretary:kb:build
---

# 监控与告警使用手册

## 模块简介

监控与告警模块用于全面追踪系统运行状态，实现对关键性能指标（如 QPS、RPM、错误率）的实时采集与分析，支持 SLA 服务可用性监控与事件管理，并提供租户级自定义报表功能。该模块帮助运营人员及时发现系统异常、评估服务质量、跟踪试用期状态，确保业务连续性与客户满意度。

本模块不支持租户自行开关，所有功能由平台统一管理，适用于多租户 SaaS 环境下的集中化运维与运营分析。

---

## 核心功能

### 实时性能监控

通过 `/monitoring/metrics` 接口获取系统整体性能数据，包括：
- 当前每秒请求数（QPS）
- 每分钟请求数（RPM）
- 错误率
- 活跃租户数量
- 活跃用户数量

在租户维度，可通过 `/tenant/monitoring/metrics` 获取更详细的指标，包含：
- 各接口路径的调用分布情况（endpoint_distribution）

该功能可用于快速判断系统负载水平、识别高流量或异常请求路径。

### 告警事件查询

通过 `/monitoring/alerts` 接口可查看历史告警记录，返回数据包含告警发生时间、类型、影响范围等信息。该接口支持运营人员追溯过往告警事件，辅助进行故障复盘与趋势分析。

### SLA 监控与事件管理

SLA 监控服务提供完整的事件生命周期管理能力，包括：
- 记录停机事件（recordDowntime）、降级事件（recordDegradation）
- 解决并关闭正在进行的事件（resolveEvent）
- 查询当前进行中的事件（getActiveEvents）
- 查看完整事件历史（history）

系统会自动计算指定时间段内的服务可用性百分比（calculateAvailability），并与预设的 SLA 阈值对比，判断是否违约（checkSlaBreaches）。若触发违约条件，将自动触发告警机制。

### 租户自定义报表

支持为每个租户创建个性化报表，实现数据可视化与定期推送。主要功能包括：
- 创建新报表（createReport）
- 更新已有报表配置（updateReport）
- 删除报表（软删除，保留历史数据）
- 获取单个报表详情（getReport）
- 分页列出当前租户所有报表（listReports）
- 生成报表所需的数据（generateData）
- 将报表导出为指定格式（export）
- 设置定时发送规则（schedule）
- 发送报表至指定接收人（sendReport）
- 应用预置模板快速构建报表（applyTemplate）

报表可配置的时间范围、维度、频率、接收人及导出格式，满足不同场景下的运营分析需求。

### 试用期管理

支持对租户试用期的全周期管理，包括：
- 启动试用期（startTrial）
- 判断是否处于试用期内（isInTrial）
- 查询试用状态（getTrialStatus）
- 手动延长试用期（extendTrial）
- 自动处理即将到期的试用期（processExpiringTrials）
- 自动处理已到期的试用期（processExpiredTrials）

该功能可配合通知机制，实现对试用期临近结束或已过期租户的主动提醒与策略执行。

---

## 常见操作流程

### 1. 查看系统整体性能状态

- 访问接口：`GET /monitoring/metrics`
- 返回内容：包含 QPS、RPM、错误率、活跃租户数、活跃用户数等核心指标。
- 用途：用于日常系统健康度巡检，快速定位性能瓶颈。

### 2. 定位租户级性能问题

- 访问接口：`GET /tenant/monitoring/metrics`
- 返回内容：除通用指标外，包含各接口路径的调用分布（endpoint_distribution）。
- 用途：分析特定租户的接口访问模式，识别高频或异常调用路径。

### 3. 查询历史告警记录

- 访问接口：`GET /monitoring/alerts`
- 返回内容：告警事件列表，含事件类型、发生时间、状态等。
- 用途：用于故障回溯、统计告警频次、评估系统稳定性。

### 4. 处理 SLA 违约事件

- 当系统检测到 SLA 可用性低于阈值时，自动触发告警。
- 运营人员可通过 `getActiveEvents()` 获取当前未解决的事件。
- 对于已确认的问题，调用 `resolveEvent()` 关闭事件。
- 使用 `history()` 查询历史事件，用于生成合规报告。

### 5. 创建并发送自定义报表

- 调用 `createReport()` 创建新报表，填写名称、描述、数据维度、时间范围、频率等。
- 使用 `applyTemplate()` 快速加载标准模板。
- 调用 `generateData()` 生成报表数据。
- 设置 `schedule()` 定时任务表达式。
- 调用 `sendReport()` 将报表发送给指定接收人。
- 支持导出为多种格式（如 PDF、Excel），通过 `export()` 实现。

### 6. 管理租户试用期

- 新租户注册后，调用 `startTrial()` 启动试用期。
- 通过 `isInTrial()` 判断当前是否仍在试用期内。
- 在试用期结束前，系统自动调用 `processExpiringTrials()` 发送提醒。
- 若需延长，管理员可手动调用 `extendTrial()`。
- 试用期结束后，系统自动执行 `processExpiredTrials()`，触发相应策略。

---

## 相关配置说明

- **报表配置**：通过 `CustomReport` 模型字段控制报表行为，包括时间范围、频率、接收人、格式、状态等。
- **SLA 配置**：依赖系统预设的 SLA 阈值与事件等级规则，具体数值由平台配置决定，不对外暴露。
- **告警触发逻辑**：基于 SLA 计算结果与事件状态自动判定，无需手动设置阈值。
- **数据存储**：所有监控数据（如 `MetricsSnapshot`、`SlaEvent`、`DeadLetter`）均按租户隔离存储，确保数据安全与审计可追溯。

> 注：本模块无前端控制台菜单路径，所有功能通过 API 接口调用实现，建议结合后台管理系统或自动化脚本完成操作。
