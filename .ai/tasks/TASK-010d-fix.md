# TASK-010d 修复指令（第 4 次重试）

## 上次失败原因
依赖时序错乱——TASK-010d 在 TASK-010a/b/c 尚未提交 AiGatewayService.php 时被并行调度，模型探索阶段 grep 发现目标类不存在（0 matches），3 轮循环后上下文耗尽/超时退出

## 修复要求
确保 TASK-010a/b/c 全部 status=REVIEWED 后再调度 TASK-010d；prompt 中直接内联 AiGatewayService.php 公开方法签名(chat/complete/embed/streamChat 及参数、返回值格式)、config/ai.php 配置结构和 AiProviderContract 接口定义，使 DEV 无需探索即可直接编写测试文件

## 注意
- 严格遵守原任务的范围约束
- 只修复 REVIEW 指出的问题
- 不要引入新变更
