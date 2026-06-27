请 Review 以下代码变更，只评估不修改代码。

按以下格式输出，每个维度必须给出评价：

## Architecture
评估架构合理性、模块边界、依赖关系。

## Code Quality
命名规范、可读性、重复代码、复杂度。

## Type Safety
类型标注是否完整，是否有潜在的类型错误。

## Security
参照 OWASP Top 10 检查：SQL 注入、XSS、未授权访问、敏感数据暴露等。

## Performance
是否有 N+1 查询、不必要的循环、内存泄漏风险。

## Potential Bugs
边界条件、空值处理、并发问题、错误处理缺失。

## Verdict
PASS 或 FAIL

如果 FAIL，在 Verdict 下方列出【必须修复】的问题（编号列出）。
如果 PASS，可以列出【建议改进】的非阻塞问题。
