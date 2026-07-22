#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""multi_tenant_saas 框架发布脚本（自包含，仅 Python 标准库）。

框架在生产服务器以「拆分包」形式安装：
  src/Modules/<PascalCase>/<rest>  ->  vendor/dsplat/multi-tenant-saas-module-<kebab>/<rest>
  根包文件 (src/非Modules、app/、config/ ...)  ->  vendor/dsplat/multi-tenant-saas/<path>

支持命令：
  status        只读：上次commit/当前HEAD/改动文件按拆分包归类
  full          全量发布（逐模块 rsync --delete + 根包同步 + dump-autoload + cache）
  incremental   增量发布（git diff，按拆包映射推送改动）
  module        发布指定模块（--module Name[,Name2]）
  db            框架迁移由后台 migrate 执行（仅提示）
"""
import argparse
import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path

PROJECT_KEY = "multi_tenant_saas"
SCRIPT_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = SCRIPT_DIR.parent
CONFIG_FILE = SCRIPT_DIR / "config.env"

# 拆包映射（源自 .github/workflows/split.yml）：PascalCase -> kebab
MODULE_MAP = {
    "Ai": "ai", "ApiToken": "api-token", "Auth": "auth", "Billing": "billing",
    "Conversation": "conversation", "Coupon": "coupon",
    "DeveloperPortal": "developer-portal", "Domain": "domain", "Event": "event",
    "Form": "form", "Infrastructure": "infrastructure", "Logging": "logging",
    "Lottery": "lottery", "Monitoring": "monitoring", "Notification": "notification",
    "Operator": "operator", "Payment": "payment", "Platform": "platform",
    "Plugin": "plugin", "Sms": "sms", "SSL": "ssl", "Storage": "storage",
    "User": "user", "Voting": "voting", "Workflow": "workflow",
}
# 根包包含的顶层目录（src/Modules 单独走拆分包）
ROOT_DIRS = ["src", "app", "config", "database", "resources", "lang", "routes", "stubs"]
# 根包 full rsync 排除项（对齐 composer.json archive.exclude + 部署相关）
ROOT_EXCLUDES = [
    "docs", "tests", ".ai", "vendor", "storage", "public", "bootstrap",
    ".env*", "*.md", "phpunit.xml.dist", "artisan", "compose.json",
    "requirements.md", "ai-runner", "*.sh", "bin", ".github",
    "src/Modules", ".git", ".mimocode", ".vite", ".qoder",
    "node_modules", "dist", "deploy", ".phpunit.cache",
]
RSYNC_EXCLUDES = [".git", ".env", ".ai", ".mimocode", ".vite", ".qoder",
                  "node_modules", "tests", "docs", "*.log"]

CFG = {}
DRY_RUN = False
VERBOSE = False
ASSUME_YES = False


# ============================ 基础工具 ============================
def log(msg, level="INFO"):
    colors = {"INFO": "\033[0m", "CMD": "\033[2m", "DRY": "\033[36m",
              "OK": "\033[32m", "WARN": "\033[33m", "ERROR": "\033[31m"}
    reset = "\033[0m"
    print(f"{colors.get(level, '')}[{level}]{reset} {msg}", flush=True)


def die(msg, code=1):
    log(msg, "ERROR")
    sys.exit(code)


def load_config():
    global CFG
    if not CONFIG_FILE.exists():
        die(f"缺少配置文件 {CONFIG_FILE}\n请复制 config.example.env 为 config.env 并填写")
    cfg = {}
    for line in CONFIG_FILE.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        cfg[k.strip()] = v.strip().strip('"').strip("'")
    required = ["SERVER_HOST", "SERVER_USER", "WEB_ROOT", "PHP_BIN"]
    missing = [k for k in required if not cfg.get(k)]
    if missing:
        die(f"config.env 缺少必填项: {', '.join(missing)}")
    cfg.setdefault("COMPOSER_BIN", "composer")
    cfg.setdefault("VENDOR_DIR", cfg["WEB_ROOT"].rstrip("/") + "/vendor")
    CFG = cfg
    return cfg


def ssh_target():
    return f"{CFG['SERVER_USER']}@{CFG['SERVER_HOST']}"


def run_list(cmd, check=True, capture=False, cwd=None):
    if VERBOSE:
        log(f"$ {' '.join(str(c) for c in cmd)}", "CMD")
    res = subprocess.run(cmd, cwd=cwd or PROJECT_ROOT,
                         capture_output=capture, text=True)
    if check and res.returncode != 0:
        err = (res.stderr or "") if capture else ""
        die(f"本地命令失败 (rc={res.returncode}): {' '.join(str(c) for c in cmd)}\n{err}")
    return res


def git(*args, check=True):
    res = subprocess.run(["git", *args], cwd=PROJECT_ROOT,
                         capture_output=True, text=True)
    if check and res.returncode != 0:
        die(f"git {' '.join(args)} 失败:\n{res.stderr}")
    return res.stdout


def ssh_run(cmd, check=True, capture=True):
    if VERBOSE:
        log(f"$ ssh {ssh_target()} \"{cmd}\"", "CMD")
    res = subprocess.run(["ssh", "-o", "BatchMode=yes", "-o", "LogLevel=ERROR",
                          ssh_target(), cmd], capture_output=capture, text=True)
    if check and res.returncode != 0:
        err = (res.stderr or "") if capture else ""
        die(f"远程命令失败 (rc={res.returncode}): {cmd}\n{err}")
    return res


def ssh_exec(cmd, check=True):
    if DRY_RUN:
        log(f"[dry-run] ssh: {cmd}", "DRY")
        return None
    return ssh_run(cmd, check=check)


def artisan(cmd, mutate=True):
    full = f"cd {CFG['WEB_ROOT']} && {CFG['PHP_BIN']} artisan {cmd}"
    if mutate and DRY_RUN:
        log(f"[dry-run] artisan {cmd}", "DRY")
        return None
    return ssh_run(full)


# ============================ rsync ============================
def _rsync_base(delete=False, excludes=None):
    cmd = ["rsync", "-avz"]
    if delete:
        cmd.append("--delete")
    if DRY_RUN:
        cmd.append("--dry-run")
    cmd += ["-e", "ssh -o BatchMode=yes -o LogLevel=ERROR"]
    for ex in (excludes or RSYNC_EXCLUDES):
        cmd.append(f"--exclude={ex}")
    return cmd


def rsync_module(module, delete=True):
    """src/Modules/<M>/ -> vendor/dsplat/multi-tenant-saas-module-<kebab>/"""
    kebab = MODULE_MAP[module]
    src = str(PROJECT_ROOT / "src" / "Modules" / module) + "/"
    dst = f"{ssh_target()}:{CFG['VENDOR_DIR']}/dsplat/multi-tenant-saas-module-{kebab}/"
    cmd = _rsync_base(delete=delete) + [src, dst]
    log(f"rsync 模块 {module} -> module-{kebab} {'(--delete)' if delete else ''}")
    run_list(cmd)


def rsync_root():
    """根包：monorepo 根 -> vendor/dsplat/multi-tenant-saas/（不 --delete，保护 composer 元数据）"""
    src = str(PROJECT_ROOT) + "/"
    dst = f"{ssh_target()}:{CFG['VENDOR_DIR']}/dsplat/multi-tenant-saas/"
    cmd = _rsync_base(delete=False, excludes=ROOT_EXCLUDES) + [src, dst]
    log("rsync 根包 -> multi-tenant-saas (不 --delete)")
    run_list(cmd)


def rsync_batch(cwd, rel_paths, remote_base):
    """按包批量推送（--relative 保留包内相对路径）。"""
    if not rel_paths:
        return
    cmd = _rsync_base(delete=False) + ["--relative"] + list(rel_paths)
    cmd.append(f"{ssh_target()}:{remote_base}/")
    log(f"rsync 增量推送 {len(rel_paths)} 个文件 -> {remote_base.split('/dsplat/')[-1]}")
    run_list(cmd, cwd=cwd)


# ============================ 状态文件 ============================
def state_path():
    return f"{CFG['WEB_ROOT']}/.deploy-state.json"


def read_state():
    res = ssh_run(f"cat {state_path()} 2>/dev/null || echo '{{}}'", check=False)
    try:
        return json.loads(res.stdout or "{}")
    except json.JSONDecodeError:
        return {}


def write_state(commit):
    state = read_state()
    state[PROJECT_KEY] = commit
    state["_last_deploy"] = datetime.now().isoformat(timespec="seconds")
    payload = json.dumps(state, ensure_ascii=False, indent=2)
    if DRY_RUN:
        log(f"[dry-run] 写状态 {PROJECT_KEY}={commit}", "DRY")
        return
    ssh_run(f"cat > {state_path()} << 'DEPLOY_STATE_EOF'\n{payload}\nDEPLOY_STATE_EOF")
    log(f"状态已更新: {PROJECT_KEY}={commit}", "OK")


def last_commit():
    return read_state().get(PROJECT_KEY)


# ============================ git 分析 ============================
def head_commit():
    return git("rev-parse", "HEAD").strip()


def commit_exists(commit):
    res = subprocess.run(["git", "cat-file", "-e", commit],
                         cwd=PROJECT_ROOT, capture_output=True)
    return res.returncode == 0


def map_file(path):
    """返回 (remote_abs, package, within_pkg_rel, module_or_None)；不可部署返回 None。"""
    if path.startswith("src/Modules/"):
        parts = path.split("/")
        if len(parts) < 4:
            return None
        module = parts[2]
        kebab = MODULE_MAP.get(module)
        if not kebab:
            return None
        within = "/".join(parts[3:])
        pkg = f"multi-tenant-saas-module-{kebab}"
        return f"{CFG['VENDOR_DIR']}/dsplat/{pkg}/{within}", pkg, within, module
    top = path.split("/")[0]
    if top in ROOT_DIRS:
        return (f"{CFG['VENDOR_DIR']}/dsplat/multi-tenant-saas/{path}",
                "multi-tenant-saas", path, None)
    return None


def is_deployable(path):
    return map_file(path) is not None


def diff_deployable(last, include_worktree=False):
    spec = last if include_worktree else f"{last}..HEAD"
    out = git("diff", "--name-status", spec)
    upsert, deleted = [], []
    for line in out.splitlines():
        if not line.strip():
            continue
        parts = line.split("\t")
        status = parts[0]
        if status.startswith("R") or status.startswith("C"):
            if len(parts) >= 3:
                if is_deployable(parts[1]):
                    deleted.append(parts[1])
                if is_deployable(parts[2]):
                    upsert.append(parts[2])
        elif status == "D":
            if len(parts) >= 2 and is_deployable(parts[1]):
                deleted.append(parts[1])
        else:
            if len(parts) >= 2 and is_deployable(parts[1]):
                upsert.append(parts[1])
    if include_worktree:
        untracked = git("ls-files", "--others", "--exclude-standard").splitlines()
        for f in untracked:
            f = f.strip()
            if f and is_deployable(f) and f not in upsert:
                upsert.append(f)
    return upsert, deleted


def dirty_paths():
    out = git("status", "--porcelain", "--", *ROOT_DIRS)
    return [ln for ln in out.splitlines() if ln.strip()]


def group_by_package(upsert):
    """{package: {cwd, remote_base, rels[]}}"""
    groups = {}
    for path in upsert:
        m = map_file(path)
        if not m:
            continue
        _remote, pkg, within, module = m
        if module:
            cwd = PROJECT_ROOT / "src" / "Modules" / module
            remote_base = f"{CFG['VENDOR_DIR']}/dsplat/{pkg}"
            rel = within
        else:
            cwd = PROJECT_ROOT
            remote_base = f"{CFG['VENDOR_DIR']}/dsplat/multi-tenant-saas"
            rel = path
        g = groups.setdefault(pkg, {"cwd": cwd, "remote_base": remote_base, "rels": []})
        g["rels"].append(rel)
    return groups


# ============================ 部署动作 ============================
def confirm(msg):
    if ASSUME_YES or DRY_RUN:
        return True
    try:
        resp = input(f"{msg} [y/N]: ").strip().lower()
    except EOFError:
        return False
    return resp in ("y", "yes")


def dump_autoload():
    log("composer dump-autoload --optimize（重建 classmap）")
    cmd = f"cd {CFG['WEB_ROOT']} && {CFG['COMPOSER_BIN']} dump-autoload --optimize"
    ssh_exec(cmd)


def run_cache():
    log("cache:clear（清 tenant 缓存）+ config:cache")
    artisan("cache:clear", mutate=True)
    artisan("config:cache", mutate=True)


def remote_rm(paths):
    for p in paths:
        m = map_file(p)
        if not m:
            log(f"跳过删除（不可映射）: {p}", "WARN")
            continue
        remote_abs = m[0]
        log(f"删除远端文件 {p}")
        ssh_exec(f"rm -f {remote_abs}")


def local_modules():
    base = PROJECT_ROOT / "src" / "Modules"
    if not base.exists():
        return []
    return sorted(m for m in MODULE_MAP
                  if (base / m).is_dir())


# ============================ 命令实现 ============================
def resolve_baseline(args):
    last = last_commit()
    if not last:
        log("服务器无部署基线（首次部署）→ 回退 full", "WARN")
        return None
    if not commit_exists(last):
        log(f"基线 commit {last} 本地不存在 → 回退 full", "WARN")
        return None
    return last


def check_dirty(args):
    dirty = dirty_paths()
    if dirty:
        log(f"检测到 {len(dirty)} 处未提交改动:", "WARN")
        for ln in dirty[:20]:
            print(f"  {ln}", flush=True)
        if not getattr(args, "allow_dirty", False):
            die("工作区不干净。请先 git commit，或加 --allow-dirty 连同工作区部署。")
        log("--allow-dirty：将连同工作区改动一起部署", "WARN")
    return bool(dirty)


def cmd_status(args):
    head = head_commit()
    last = last_commit()
    print("=" * 60, flush=True)
    print(f"项目:        {PROJECT_KEY}（框架拆分包）")
    print(f"本地 HEAD:   {head}")
    print(f"上次部署:    {last or '(无 → 下次 incremental 将回退 full)'}")
    if last and not commit_exists(last):
        print("  ⚠ 基线 commit 本地不存在 → incremental 将回退 full")
    print("-" * 60, flush=True)
    if last and commit_exists(last):
        up, dele = diff_deployable(last)
        groups = group_by_package(up)
        print(f"自上次部署改动: {len(up)} 增改 / {len(dele)} 删除")
        for pkg, g in sorted(groups.items()):
            print(f"  [{pkg}] {len(g['rels'])} 文件")
            for r in g["rels"][:10]:
                print(f"    + {r}")
            if len(g["rels"]) > 10:
                print(f"    ... 另有 {len(g['rels']) - 10} 个")
        for f in dele[:20]:
            print(f"  - {f}")
    print("-" * 60, flush=True)
    dirty = dirty_paths()
    print(f"未提交改动:  {len(dirty)} 处" + ("（建议先提交）" if dirty else ""))
    print("=" * 60, flush=True)


def cmd_full(args):
    log("=== 全量发布 multi_tenant_saas（框架）===")
    if not confirm("即将全量发布框架（逐模块 rsync --delete + 根包同步），继续?"):
        log("已取消"); return
    mods = local_modules()
    for m in mods:
        rsync_module(m, delete=True)
    rsync_root()
    dump_autoload()
    run_cache()
    write_state(head_commit())
    log("=== 框架全量发布完成 ===", "OK")


def cmd_incremental(args):
    last = resolve_baseline(args)
    if last is None:
        log("回退到全量发布", "WARN")
        return cmd_full(args)
    dirty = check_dirty(args)
    up, dele = diff_deployable(last, include_worktree=dirty)
    log(f"=== 增量发布 multi_tenant_saas（基线 {last[:8]}）===")
    log(f"增改 {len(up)} / 删除 {len(dele)} 个文件")
    if not up and not dele:
        log("无可部署改动")
    else:
        if not confirm(f"即将增量部署 {len(up)} 增改 / {len(dele)} 删除，继续?"):
            log("已取消"); return
        groups = group_by_package(up)
        for pkg, g in groups.items():
            rsync_batch(g["cwd"], g["rels"], g["remote_base"])
        if dele:
            remote_rm(dele)
    dump_autoload()
    run_cache()
    write_state(head_commit())
    log("=== 框架增量发布完成 ===", "OK")


def cmd_module(args):
    if not args.module:
        die("module 命令需要 --module Name[,Name2]")
    names = [n.strip() for n in args.module.split(",") if n.strip()]
    available = local_modules()
    bad = [n for n in names if n not in available]
    if bad:
        die(f"模块不存在: {', '.join(bad)}\n可用模块: {', '.join(available)}")
    log(f"=== 框架模块发布: {', '.join(names)} ===")
    if not confirm(f"即将发布框架模块 {', '.join(names)}，继续?"):
        log("已取消"); return
    for name in names:
        rsync_module(name, delete=True)
    dump_autoload()
    run_cache()
    write_state(head_commit())
    log("=== 框架模块发布完成 ===", "OK")


def cmd_db(args):
    log("框架模块迁移随拆分包部署，由后台 `php artisan migrate` 执行。", "WARN")
    log("请运行后台发布脚本的 db 命令：")
    log("  cd ../scrm-platform && python3 deploy/deploy.py db")


# ============================ 入口 ============================
def build_parser():
    p = argparse.ArgumentParser(description="multi_tenant_saas 框架发布脚本")
    p.add_argument("command", choices=["status", "full", "incremental", "db", "module"])
    p.add_argument("--dry-run", action="store_true")
    p.add_argument("--yes", action="store_true")
    p.add_argument("--module", help="模块名（可逗号分隔多个）")
    p.add_argument("--allow-dirty", action="store_true")
    p.add_argument("-v", "--verbose", action="store_true")
    return p


def main():
    global DRY_RUN, VERBOSE, ASSUME_YES
    args = build_parser().parse_args()
    DRY_RUN = args.dry_run
    VERBOSE = args.verbose
    ASSUME_YES = args.yes
    load_config()
    if DRY_RUN:
        log("DRY-RUN 模式：变更操作只打印不执行", "DRY")
    handlers = {"status": cmd_status, "full": cmd_full,
                "incremental": cmd_incremental, "db": cmd_db, "module": cmd_module}
    handlers[args.command](args)


if __name__ == "__main__":
    main()
