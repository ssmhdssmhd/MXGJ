#!/bin/bash
# MXGJ 版本号自动同步脚本
# 用法: bash scripts/bump_version.sh <新版本号>
# 例: bash scripts/bump_version.sh 1.16.5

set -e
cd "$(dirname "$0")/.."

if [ -z "$1" ]; then
  echo "用法: bash scripts/bump_version.sh <新版本号>"
  echo "  当前版本: $(grep -oP "MXGJ_VERSION', '\K[^']+" lib/bootstrap.php)"
  exit 1
fi

NEW="$1"
OLD_BOOT=$(grep -oP "MXGJ_VERSION', '\K[^']+" lib/bootstrap.php)
OLD_JSON=$(python3 -c "import json; print(json.load(open('version.json'))['version'])")

echo "🔄 版本号 bump: $OLD_BOOT → $NEW"
echo ""

# 1. bootstrap.php
sed -i "s/define('MXGJ_VERSION', '$OLD_BOOT')/define('MXGJ_VERSION', '$NEW')/" lib/bootstrap.php
echo "  ✅ lib/bootstrap.php: $OLD_BOOT → $NEW"

# 2. version.json
python3 -c "
import json, datetime
d = json.load(open('version.json'))
d['version'] = '$NEW'
d['release'] = datetime.date.today().isoformat()
json.dump(d, open('version.json','w'), ensure_ascii=False, indent=2)
"
echo "  ✅ version.json: $OLD_JSON → $NEW"

# 3. 验证
echo ""
echo "📋 验证:"
echo "  bootstrap.php: $(grep -oP "MXGJ_VERSION', '\K[^']+" lib/bootstrap.php)"
echo "  version.json:  $(python3 -c "import json; print(json.load(open('version.json'))['version'])")"

# 4. php -l
if command -v php &>/dev/null; then
  php -l lib/bootstrap.php 2>&1 | tail -1 | sed 's/^/  /'
fi

echo ""
echo "✅ 版本同步完成！下一步: git add -A && git commit -m \"v$NEW: ...\" && git push"
