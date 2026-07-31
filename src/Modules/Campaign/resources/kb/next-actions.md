---
module: campaign
audience: internal
locale: zh
title: 计划编排模块典型后续动作
---

# 计划编排（Campaign）典型后续动作

## 策划草稿后（`campaign_plan_draft`）

- 与用户逐项确认计划内容（阶段、任务、时间锚点），迭代修改草稿
- 确认无误后 `campaign_plan_commit` 定稿：编译为定时任务并自动进入持续跟进（tracked）

## 计划定稿后（`campaign_plan_commit`）

- `campaign_status` 随时查看任务执行进度与待确认项
- 到点任务由系统自动执行或通知用户人工确认，无需手动盯守
- 计划外的营销/传播遗漏：用 `thread_review` 获取该脉络全貌（任务分布、关联资产、历史会话），结合能力图谱推断补齐

## 持续跟进

- 值得长期关注但尚无计划的事项：提议后经用户确认用 `thread_track` 建立跟踪（`thread_untrack` 取消）
- 系统每日巡检 tracked 脉络（逾期/停滞/临近里程碑），结果注入后续会话自动带出
