# TASK-010c 修复指令（第 4 次重试）

## 上次失败原因
模型（glm-5.2）因并行依赖（010a/010b）未就绪而陷入探索循环，744行日志全在读文件，未产出任何代码

## 修复要求
直接在prompt中内联AiProviderContract接口签名、AiModelEnum值列表、config/ai.php结构和AiRequest/AiModelAlias模型字段定义，禁止探索阶段，要求立即开始写代码

## 注意
- 严格遵守原任务的范围约束
- 只修复 REVIEW 指出的问题
- 不要引入新变更
