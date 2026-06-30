#!/usr/bin/env python3
from __future__ import annotations
"""
ID 合规检查器

检查模型和迁移文件是否符合 ID 规范：
- 模型必须使用 HasGlobalId trait
- 主键必须是 16 位整数
- 迁移不能使用 $table->id()

用法:
    python3 check.py                    # 检查所有文件
    python3 check.py --staged           # 只检查 git staged 文件
    python3 check.py --fix              # 自动修复（仅迁移文件）
"""

import re
import subprocess
import sys
from pathlib import Path
from typing import Optional
from dataclasses import dataclass, field


@dataclass
class Violation:
    """违规项"""
    file: Path
    line: int
    message: str
    severity: str = "error"  # error, warning


@dataclass
class CheckResult:
    """检查结果"""
    violations: list[Violation] = field(default_factory=list)
    files_checked: int = 0
    
    @property
    def has_errors(self) -> bool:
        return any(v.severity == "error" for v in self.violations)
    
    def summary(self) -> str:
        errors = sum(1 for v in self.violations if v.severity == "error")
        warnings = sum(1 for v in self.violations if v.severity == "warning")
        return f"检查 {self.files_checked} 个文件: {errors} 错误, {warnings} 警告"


class IDComplianceChecker:
    """ID 合规检查器"""
    
    # 豁免列表
    EXEMPT_MODELS = {"Customer"}  # 第三方模型
    EXEMPT_MIGRATIONS = {"personal_access_tokens"}  # Laravel Sanctum
    
    def __init__(self, project_dir: Path):
        self.project_dir = project_dir
        self.models_dir = project_dir / "src" / "Models"
        self.migrations_dir = project_dir / "database" / "migrations"
    
    def check_all(self, staged_only: bool = False) -> CheckResult:
        """检查所有文件"""
        result = CheckResult()
        
        # 检查模型
        model_files = self._get_model_files(staged_only)
        for f in model_files:
            result.files_checked += 1
            result.violations.extend(self._check_model(f))
        
        # 检查迁移
        migration_files = self._get_migration_files(staged_only)
        for f in migration_files:
            result.files_checked += 1
            result.violations.extend(self._check_migration(f))
        
        return result
    
    def _get_model_files(self, staged_only: bool) -> list[Path]:
        """获取模型文件列表"""
        if staged_only:
            return self._get_staged_files("src/Models/*.php")
        else:
            return list(self.models_dir.glob("*.php"))
    
    def _get_migration_files(self, staged_only: bool) -> list[Path]:
        """获取迁移文件列表"""
        if staged_only:
            return self._get_staged_files("database/migrations/*.php")
        else:
            return list(self.migrations_dir.glob("*.php"))
    
    def _get_staged_files(self, pattern: str) -> list[Path]:
        """获取 git staged 的文件"""
        result = subprocess.run(
            ["git", "diff", "--cached", "--name-only", "--", pattern],
            cwd=self.project_dir,
            capture_output=True, text=True
        )
        if result.returncode != 0:
            return []
        
        files = []
        for line in result.stdout.strip().split('\n'):
            if line:
                files.append(self.project_dir / line)
        return files
    
    def _check_model(self, file: Path) -> list[Violation]:
        """检查单个模型文件"""
        violations = []
        
        # 检查豁免
        model_name = file.stem
        if model_name in self.EXEMPT_MODELS:
            return violations
        
        content = file.read_text(encoding='utf-8')
        lines = content.split('\n')
        
        # 检查 HasGlobalId
        has_global_id = "HasGlobalId" in content
        if not has_global_id:
            violations.append(Violation(
                file=file,
                line=1,
                message=f"模型 {model_name} 缺少 HasGlobalId trait",
                severity="error"
            ))
        
        # 检查 primaryKey 命名
        pk_match = re.search(r'protected\s+\$primaryKey\s*=\s*[\'"]([^\'"]+)[\'"]', content)
        if pk_match:
            pk = pk_match.group(1)
            expected_pk = f"{self._to_snake_case(model_name)}_id"
            if pk != expected_pk and pk != "id":
                # 找到行号
                for i, line in enumerate(lines, 1):
                    if "$primaryKey" in line:
                        violations.append(Violation(
                            file=file,
                            line=i,
                            message=f"主键命名不规范: '{pk}' 应为 '{expected_pk}'",
                            severity="warning"
                        ))
                        break
        
        return violations
    
    def _check_migration(self, file: Path) -> list[Violation]:
        """检查单个迁移文件"""
        violations = []
        
        # 检查豁免
        filename = file.name
        if any(exempt in filename for exempt in self.EXEMPT_MIGRATIONS):
            return violations
        
        content = file.read_text(encoding='utf-8')
        lines = content.split('\n')
        
        # 检查 $table->id()
        for i, line in enumerate(lines, 1):
            if re.search(r'\$table\s*->\s*id\s*\(', line):
                violations.append(Violation(
                    file=file,
                    line=i,
                    message="迁移使用了 $table->id()，应使用 $table->unsignedBigInteger() 并配合 IdGenerator",
                    severity="error"
                ))
        
        return violations
    
    @staticmethod
    def _to_snake_case(name: str) -> str:
        """转换为蛇形命名"""
        s1 = re.sub('(.)([A-Z][a-z]+)', r'\1_\2', name)
        return re.sub('([a-z0-9])([A-Z])', r'\1_\2', s1).lower()


def print_violations(result: CheckResult, verbose: bool = True) -> None:
    """打印违规详情"""
    if not result.violations:
        print("✓ 无违规项")
        return
    
    for v in result.violations:
        icon = "✗" if v.severity == "error" else "⚠"
        rel_path = v.file.relative_to(v.file.parents[3]) if len(v.file.parts) > 3 else v.file
        print(f"{icon} {rel_path}:{v.line}: {v.message}")
    
    print()
    print(result.summary())


def main():
    import argparse
    
    parser = argparse.ArgumentParser(description="ID 合规检查器")
    parser.add_argument("--staged", action="store_true", 
                       help="只检查 git staged 文件")
    parser.add_argument("--fix", action="store_true",
                       help="自动修复（仅迁移文件）")
    parser.add_argument("--project-dir", type=Path,
                       help="项目根目录")
    
    args = parser.parse_args()
    
    # 获取项目目录
    if args.project_dir:
        project_dir = args.project_dir
    else:
        result = subprocess.run(
            ["git", "rev-parse", "--show-toplevel"],
            capture_output=True, text=True
        )
        if result.returncode == 0:
            project_dir = Path(result.stdout.strip())
        else:
            project_dir = Path.cwd()
    
    # 执行检查
    checker = IDComplianceChecker(project_dir)
    result = checker.check_all(staged_only=args.staged)
    
    # 输出结果
    print_violations(result)
    
    # 返回退出码
    return 1 if result.has_errors else 0


if __name__ == "__main__":
    sys.exit(main())
