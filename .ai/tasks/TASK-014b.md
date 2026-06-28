# TASK-014b: 知识库模块（RAG）

**Sprint:** sprint-003  
**状态:** READY  
**依赖:** TASK-010（AiGatewayService）、TASK-011（AiTextService 嵌入向量）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现租户级知识库，支持文档导入、分块嵌入、向量检索和 RAG 问答。

---

## 范围

**只允许修改：**
- `src/Services/KnowledgeBaseService.php`（新建）
- `src/Services/DocumentIngestionService.php`（新建）
- `src/Services/EmbeddingService.php`（新建）
- `src/Services/VectorStoreService.php`（新建）
- `src/Services/RetrievalService.php`（新建）
- `src/Services/RagService.php`（新建）
- `src/Contracts/VectorStoreContract.php`（新建）
- `src/VectorStore/MysqlVectorStore.php`（新建）
- `src/VectorStore/RedisVectorStore.php`（新建）
- `src/Models/KnowledgeBase.php`（新建）
- `src/Models/KnowledgeDocument.php`（新建）
- `src/Models/KnowledgeChunk.php`（新建）
- `database/migrations/` 下新增 knowledge_bases、knowledge_documents、knowledge_chunks 迁移
- `config/ai.php`（追加知识库配置）
- `src/TenancyServiceProvider.php`（注册 KB 相关 singleton）
- `composer.json`、`composer.lock`（追加文档解析依赖）
- `lang/zh_CN/ai.php`、`lang/en/ai.php`（追加翻译 key）
- `tests/KnowledgeBaseServiceTest.php`（新建）
- `tests/DocumentIngestionServiceTest.php`（新建）
- `tests/VectorStoreServiceTest.php`（新建）
- `tests/RagServiceTest.php`（新建）
- `tests/TestCase.php`（追加新表 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### KnowledgeBaseService

知识库 CRUD、分类管理、标签管理、访问控制（租户隔离）、元数据管理、文档/分块计数统计

### DocumentIngestionService

1. 文档上传（PDF/Word/Markdown/TXT/HTML）
2. 文本提取（smalot/pdfparser、phpoffice/phpword、league/html-to-markdown）
3. 分块策略：固定长度+重叠 / 段落分块 / 语义分块
4. 分块元数据（来源、位置）
5. 处理状态：pending/processing/ready/failed
6. 错误处理和重试

### EmbeddingService

调用 AiTextService 嵌入接口生成向量、批量嵌入（异步队列）、模型选择、维度管理

### VectorStoreContract

统一接口：store/search/delete/update

### MysqlVectorStore

MySQL JSON 存储向量，PHP 端余弦相似度（<10万分块），支持租户+KB 过滤

### RedisVectorStore

Redis Stack（RediSearch）存储检索，HNSW 索引、混合过滤

### RetrievalService

查询嵌入生成、向量检索、重排序（相似度+元数据权重）、上下文窗口管理、引用来源追踪

### RagService

端到端 RAG：查询→检索→增强提示词→AiTextService生成→返回回答+引用来源，支持多轮对话

### 数据模型

1. `knowledge_bases` 表: 租户ID、名称、描述、分类、标签(JSON)、状态、文档数、分块数、嵌入模型、向量维度
2. `knowledge_documents` 表: KB ID、文件名、文件类型、文件路径、文本内容(LONGTEXT)、分块数、处理状态、错误信息、元数据(JSON)
3. `knowledge_chunks` 表: 文档ID、KB ID、内容(TEXT)、位置、嵌入向量(JSON)、维度、元数据(JSON)

> 文档解析需引入: smalot/pdfparser、phpoffice/phpword、league/html-to-markdown

> 向量存储策略: MySQL JSON 供开发，生产用 Redis Stack。VectorStoreContract 确保可插拔。

---

## 验收标准

- [ ] 知识库 CRUD 正常，租户隔离正常
- [ ] 文档上传+文本提取+分块正常
- [ ] 嵌入向量生成正常
- [ ] MysqlVectorStore 向量存储和检索正常
- [ ] RAG 端到端问答正常（检索→增强→生成→引用来源）
- [ ] 多轮对话携带检索上下文正常
- [ ] composer 依赖安装正常
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- 模型 use HasTenantScope
- VectorStoreService 和 RagService 注册为 singleton
- 文档解析 CPU 密集，使用队列异步处理
- RedisVectorStore 不可用时降级到 MysqlVectorStore
- 嵌入向量维度取决于模型（text-embedding-3-small 为 1536 维）
---

## 全局规范声明

> **⚠ 严格遵守全局约束 — 此部分适用于本任务的所有子任务（a/b/c/d...），无例外**

### 1. 禁止修改的文件

- **`.ai/scripts/` 目录下任何文件**（loop-run.sh、parallel-run.sh、loop-watch.sh、plan-task.sh、lib.sh 等）
- **`.ai/prompts/` 目录下任何文件**（dev-prompt.md、review-prompt.md、plan-prompt.md 等）
- 如 AI 在执行过程中发现需要修改上述文件，应**停止并向用户报告**，而不是自行修改

### 2. 编码规范

- 遵循 **PSR-12** 规范，使用 **Laravel 最佳实践**
- 所有 Controller 必须使用 **API Resource** 返回数据，禁止直接返回模型或数组
- 敏感字段（password/token/secret/key）**永不返回**，手机号脱敏
- 所有方法参数必须有**类型声明**，所有方法必须有**返回值类型声明**
- 使用 PHP 8.1+ 特性（枚举、只读属性等）
- 使用中文注释 + PHPDoc

### 3. 多语言规范

- 使用 `trans()` / `__()` 函数实现多语言，**禁止硬编码中文字符串**
- 新增翻译 key 必须同时添加到 `lang/zh_CN/` 和 `lang/en/` 两个目录

### 4. 数据库规范

- 迁移文件命名接续现有序号（查看 `database/migrations/` 最大序号后 +1）
- 新建模型 use `HasTenantScope` trait 实现租户隔离
- Service 类通过 `TenancyServiceProvider` 注册为 singleton

### 5. 响应格式

- 统一用 `ApiResponse::success()` 和 `ApiResponse::error()`
- 错误码标准化，HTTP 状态码正确

### 6. 测试规范

- 每个新建 Service 必须有对应的 Test 文件
- 测试继承 `tests/TestCase.php`，如需新表 schema 在 TestCase.php 中追加
- `php vendor/bin/phpunit` 全绿（预存在的失败除外，但不得新增失败）
