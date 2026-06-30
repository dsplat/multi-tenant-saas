#!/opt/homebrew/bin/bash
# =============================================================================
# check-id-compliance.sh — 全局ID生成器规范合规检查
# 用法: .ai/scripts/check-id-compliance.sh [--staged]
#
# 检查项：
# 1. 模型文件必须使用 HasGlobalId trait
# 2. 模型文件必须定义正确的 primaryKey（{model}_id）
# 3. 迁移文件禁止使用 $table->id()（自增）
# =============================================================================

set -o pipefail

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 统计
errors=0
warnings=0

# 获取要检查的文件
if [[ "${1:-}" == "--staged" ]]; then
    # 只检查 git staged 文件
    MODEL_FILES=$(git diff --cached --name-only --diff-filter=ACM 2>/dev/null | grep -E "^src/Models/.*\.php$|^src/Modules/.*/Models/.*\.php$|^app/Models/.*\.php$" || true)
    MIGRATION_FILES=$(git diff --cached --name-only --diff-filter=ACM 2>/dev/null | grep -E "^database/migrations/.*\.php$" || true)
else
    # 检查所有文件
    MODEL_FILES=$(find src/Models src/Modules app/Models -name "*.php" -type f 2>/dev/null | grep -i model || true)
    MIGRATION_FILES=$(find database/migrations -name "*.php" -type f 2>/dev/null || true)
fi

# 豁免列表
EXEMPT_MODEELS="Customer"  # 示例/演示模型
EXEMPT_MIGRATIONS="personal_access_tokens"  # Laravel Sanctum 内置表

echo "=============================================="
echo "  全局ID生成器规范合规检查"
echo "=============================================="
echo ""

# =============================================================================
# 检查模型文件
# =============================================================================
if [[ -n "$MODEL_FILES" ]]; then
    echo "📋 检查模型文件..."
    
    while IFS= read -r file; do
        [[ -z "$file" ]] && continue
        [[ ! -f "$file" ]] && continue
        
        model_name=$(basename "$file" .php)
        
        # 跳过豁免模型
        if echo "$EXEMPT_MODEELS" | grep -qw "$model_name"; then
            continue
        fi
        
        # 跳过非 Eloquent 模型（如 Contract、Trait）
        if ! grep -q "extends Model\|extends.*Model" "$file" 2>/dev/null; then
            continue
        fi
        
        # 检查 HasGlobalId
        if ! grep -q "HasGlobalId" "$file"; then
            echo -e "  ${RED}✗ $file: 缺少 HasGlobalId trait${NC}"
            ((errors++))
        fi
        
        # 检查 primaryKey 定义
        if grep -q "primaryKey" "$file"; then
            pk=$(grep "protected \$primaryKey" "$file" | sed "s/.*= *'\(.*\)'.*/\1/" | head -1)
            if [[ "$pk" == "id" ]]; then
                echo -e "  ${RED}✗ $file: primaryKey 不应为 'id'，应为 '${model_name}_id'${NC}"
                ((errors++))
            fi
        else
            # 没有显式定义 primaryKey，默认是 'id'
            echo -e "  ${YELLOW}⚠ $file: 未定义 primaryKey（默认 'id'），建议显式定义${NC}"
            ((warnings++))
        fi
        
    done <<< "$MODEL_FILES"
    
    echo ""
fi

# =============================================================================
# 检查迁移文件
# =============================================================================
if [[ -n "$MIGRATION_FILES" ]]; then
    echo "📋 检查迁移文件..."
    
    while IFS= read -r file; do
        [[ -z "$file" ]] && continue
        [[ ! -f "$file" ]] && continue
        
        filename=$(basename "$file")
        
        # 跳过豁免迁移
        skip=false
        for exempt in $EXEMPT_MIGRATIONS; do
            if echo "$filename" | grep -q "$exempt"; then
                skip=true
                break
            fi
        done
        [[ "$skip" == "true" ]] && continue
        
        # 检查 $table->id()
        if grep -q '\$table->id()' "$file" 2>/dev/null; then
            line_nums=$(grep -n '\$table->id()' "$file" 2>/dev/null | cut -d: -f1 | paste -sd ',' -)
            echo -e "  ${RED}✗ $file: 使用了 \$table->id()（行号: ${line_nums:-?}）${NC}"
            echo -e "    ${YELLOW}→ 应改为 \$table->unsignedBigInteger('{model}_id')->primary()${NC}"
            ((errors++))
        fi
        
        # 检查 $table->increments() / $table->bigIncrements()
        if grep -q '\$table->increments\|\$table->bigIncrements' "$file" 2>/dev/null; then
            line_nums=$(grep -n '\$table->increments\|\$table->bigIncrements' "$file" 2>/dev/null | cut -d: -f1 | paste -sd ',' -)
            echo -e "  ${RED}✗ $file: 使用了自增ID（行号: ${line_nums:-?}）${NC}"
            ((errors++))
        fi
        
    done <<< "$MIGRATION_FILES"
    
    echo ""
fi

# =============================================================================
# 输出结果
# =============================================================================
echo "=============================================="
if [[ $errors -gt 0 ]]; then
    echo -e "  ${RED}检查失败: $errors 个错误, $warnings 个警告${NC}"
    echo "=============================================="
    exit 1
else
    echo -e "  ${GREEN}检查通过! $warnings 个警告${NC}"
    echo "=============================================="
    exit 0
fi
