# TASK-013 修复指令（第 2 次重试）

## 上次失败原因
代码已完整生成且测试通过(22/22)、review PASS，但编排器在 git commit + state.json 更新的后处理阶段中断，导致任务卡在 FIX_REQUESTED 状态未闭环

## 修复要求
无需重新生成代码——代码已提交(d4abf2c)且review已PASS，直接将 state.json 中 TASK-013 状态从 FIX_REQUESTED 更新为 DONE 即可

## 注意
- 严格遵守原任务的范围约束
- 只修复 REVIEW 指出的问题
- 不要引入新变更
