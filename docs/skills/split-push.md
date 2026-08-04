# Split Push - 推送模块到最新

将 monorepo 中的模块拆分推送到独立 GitHub 仓库。

## 使用场景

- 代码修改后，同步更新所有模块仓库
- 新增模块后，添加到 split 矩阵
- 验证 split workflow 状态

## 工作流程

### 1. 检查当前状态

```bash
# 检查 workflow 最近运行
gh run list --workflow=split.yml --limit 5

# 查看具体运行详情
gh run view <run-id>
```

### 2. 触发 split workflow

```bash
# 手动触发
gh workflow run split.yml --ref main

# 等待完成
sleep 180 && gh run list --limit 3
```

### 3. 验证结果

```bash
# 检查成功数量
gh run view <run-id> --json jobs -q '.jobs | length'

# 检查失败的 job
gh run view <run-id> | grep -E "✓|X"
```

### 4. 添加新模块

编辑 `.github/workflows/split.yml`，在 matrix 中添加：

```yaml
- prefix: 'src/Modules/NewModule'
  repo: multi-tenant-saas-module-new-module
```

## 当前模块清单

| # | 包名 | 说明 |
|---|------|------|
| 1 | dsplat/multi-tenant-saas | 核心包 |
| 2 | dsplat/multi-tenant-saas-module-ai | AI |
| 3 | dsplat/multi-tenant-saas-module-ai-streaming | AI 流式处理 |
| 4 | dsplat/multi-tenant-saas-module-api-token | API Token |
| 5 | dsplat/multi-tenant-saas-module-auth | 认证 |
| 6 | dsplat/multi-tenant-saas-module-billing | 计费 |
| 7 | dsplat/multi-tenant-saas-module-campaign | 营销活动 |
| 8 | dsplat/multi-tenant-saas-module-commerce | 电商/订单 |
| 9 | dsplat/multi-tenant-saas-module-contracts | 合同管理 |
| 10 | dsplat/multi-tenant-saas-module-conversation | 会话 |
| 11 | dsplat/multi-tenant-saas-module-coupon | 优惠券 |
| 12 | dsplat/multi-tenant-saas-module-developer-portal | 开发者门户 |
| 13 | dsplat/multi-tenant-saas-module-domain | 域名 |
| 14 | dsplat/multi-tenant-saas-module-event | 事件 |
| 15 | dsplat/multi-tenant-saas-module-form | 表单 |
| 16 | dsplat/multi-tenant-saas-module-ibot | 智能机器人 |
| 17 | dsplat/multi-tenant-saas-module-infrastructure | 基础设施 |
| 18 | dsplat/multi-tenant-saas-module-knowledge | 知识库 |
| 19 | dsplat/multi-tenant-saas-module-logging | 日志 |
| 20 | dsplat/multi-tenant-saas-module-lottery | 抽奖 |
| 21 | dsplat/multi-tenant-saas-module-monitoring | 监控 |
| 22 | dsplat/multi-tenant-saas-module-notification | 通知 |
| 23 | dsplat/multi-tenant-saas-module-operator | 运营人员 |
| 24 | dsplat/multi-tenant-saas-module-payment | 支付 |
| 25 | dsplat/multi-tenant-saas-module-platform | 平台 |
| 26 | dsplat/multi-tenant-saas-module-plugin | 插件 |
| 27 | dsplat/multi-tenant-saas-module-sms | 短信 |
| 28 | dsplat/multi-tenant-saas-module-ssl | SSL |
| 29 | dsplat/multi-tenant-saas-module-storage | 存储 |
| 30 | dsplat/multi-tenant-saas-module-ticket | 工单系统 |
| 31 | dsplat/multi-tenant-saas-module-user | 用户 |
| 32 | dsplat/multi-tenant-saas-module-voting | 投票 |
| 33 | dsplat/multi-tenant-saas-module-workflow | 工作流 |

## 注意事项

- Split workflow 使用 `git subtree split` + PAT push
- `actions/checkout` 必须使用 `token: ${{ secrets.SPLIT_TOKEN }}`
- Contracts 不单独拆分，随核心包发布
- 每次运行只需 1 次 API 调用（检查仓库是否存在）
