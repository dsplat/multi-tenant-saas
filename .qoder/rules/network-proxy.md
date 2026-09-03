---
trigger: always_on
alwaysApply: true
---

# 网络命令代理规则（git / composer / npm / gh）

本地直连 GitHub/npm 源慢或不稳定，所有需要外网的包管理与 Git 远程操作**必须优先走本地代理**。

## 代理地址

- `http://127.0.0.1:7890`（HTTP/SOCKS 混合端口，Clash 系）
- 使用前可快速探测：`nc -z -w1 127.0.0.1 7890`（不通时再降级直连并告知用户）

## 用法（环境变量临时注入，禁止写入 git config）

```bash
# git 远程操作（push / pull / fetch / clone）
https_proxy=http://127.0.0.1:7890 http_proxy=http://127.0.0.1:7890 git push origin main

# composer（install / update / create-project）
https_proxy=http://127.0.0.1:7890 http_proxy=http://127.0.0.1:7890 composer update "dsplat/*" --with-dependencies

# npm（install / publish / npx 拉包）
https_proxy=http://127.0.0.1:7890 http_proxy=http://127.0.0.1:7890 npm install

# gh CLI（run watch / release 等访问 api.github.com）
https_proxy=http://127.0.0.1:7890 gh run watch <run_id> --exit-status
```

## 铁律

- ✅ 用**环境变量前缀**注入（单命令生效，不污染全局配置）
- ❌ 禁止 `git config --global http.proxy ...`（污染用户全局配置）
- ❌ 禁止把代理写入 `.env`、composer.json `config`、`.npmrc` 等落盘文件
- 本地资源操作（`git add/commit`、`php artisan test`、sqlite 测试）**不需要**代理
- zsh 下 composer 包名通配符必须加引号：`"dsplat/*"`（防 glob 展开，见 common_pitfalls）

## 典型组合（框架发布一条龙）

```bash
# 1. 框架推送（触发 split）
https_proxy=http://127.0.0.1:7890 git push origin main
# 2. 监控拆包
https_proxy=http://127.0.0.1:7890 gh run watch <run_id> --repo dsplat/multi-tenant-saas --exit-status
# 3. 下游更新依赖
https_proxy=http://127.0.0.1:7890 http_proxy=http://127.0.0.1:7890 composer update "dsplat/multi-tenant-saas" "dsplat/multi-tenant-saas-module-*" --with-dependencies
```
