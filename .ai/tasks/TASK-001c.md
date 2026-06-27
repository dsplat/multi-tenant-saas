# TASK-001c: 翻译键补全与时间窗口计算修复

**Sprint:** sprint-001
**状态:** READY
**预估时间:** 1.5 小时
**依赖:** 无（可与 TASK-001a / TASK-001b 并行）
**来源:** TASK-001 ESCALATE 拆分

---

## 目标

补全缺失的 payment.* 和 common.* 翻译键，修正 PerformanceService 时间窗口计算逻辑错误。

---

## 范围

**只允许修改：**
- `lang/en/payment.php`
- `lang/en/common.php`
- `lang/zh_CN/payment.php`
- `lang/zh_CN/common.php`
- `app/Services/PerformanceService.php`

**禁止：**
- 修改其他任何文件
- 修改业务逻辑（PerformanceService 只修正时间窗口公式）

---

## 验收标准

- [ ] `lang/en/payment.php` 和 `lang/zh_CN/payment.php` — 补充 23 个缺失的 `payment.*` 翻译键（en/zh_CN 各一份）
- [ ] `lang/en/common.php` 和 `lang/zh_CN/common.php` — 补充 27 个缺失的 `common.*` 翻译键（en/zh_CN 各一份）
- [ ] `PerformanceService.php:214` — 时间窗口起始时间计算修正为：
  ```php
  $windowStart = floor(time() / ($windowMinutes * 60)) * ($windowMinutes * 60);
  ```

---

## 给 AI 的补充说明

- 翻译键命名参考项目已有的 `lang/en/` 文件风格
- 缺失的具体键名从 `.ai/review/TASK-001-review.md` 的 Issue #5 中获取
- 时间窗口公式：当前时间对窗口长度取整，确保同一窗口内的请求用同一个 key

---

## 状态流转记录

| 时间 | 状态 | 备注 |
|------|------|------|
| 2026-06-27 | READY | 从 TASK-001 ESCALATE 拆分 |
