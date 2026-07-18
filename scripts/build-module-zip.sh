#!/usr/bin/env bash
# Gera o ZIP distribuível do módulo PrestaShop SmartVitrines.
# O ZIP contém a pasta `smartvitrines/` no topo, pronto para instalar em modules/.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MODULE_DIR="$ROOT"
OUT_DIR="$ROOT/dist"

if [[ ! -f "$MODULE_DIR/config.xml" ]]; then
  echo "ERRO: config.xml do módulo não encontrado em $MODULE_DIR" >&2
  exit 1
fi

VERSION="$(grep -o '<version><!\[CDATA\[[^]]*' "$MODULE_DIR/config.xml" | sed -E 's/.*\[CDATA\[//')"
if [[ -z "${VERSION:-}" ]]; then
  echo "ERRO: não consegui ler a versão em config.xml" >&2
  exit 1
fi

PHP_VERSION="$(grep -oE "this->version\s*=\s*'[^']+'" "$MODULE_DIR/smartvitrines.php" | sed -E "s/.*'([^']+)'.*/\1/")"
if [[ -n "${PHP_VERSION:-}" && "$PHP_VERSION" != "$VERSION" ]]; then
  echo "ERRO: versão divergente — config.xml=$VERSION smartvitrines.php=$PHP_VERSION" >&2
  exit 1
fi

OUT="$OUT_DIR/smartvitrines-${VERSION}.zip"
mkdir -p "$OUT_DIR"
rm -f "$OUT"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

rsync -a \
  --exclude '.git' \
  --exclude 'dist' \
  --exclude '.github' \
  --exclude '.bak-*' \
  --exclude 'scripts/diag*.php' \
  --exclude 'scripts/setup-local-dev.php' \
  "$MODULE_DIR/" "$STAGE/smartvitrines/"

if command -v zip >/dev/null 2>&1; then
  ( cd "$STAGE" && zip -rq -X "$OUT" smartvitrines )
else
  python3 - "$STAGE" "$OUT" <<'PY'
import os, sys, zipfile
stage, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
    for root, _dirs, files in os.walk(os.path.join(stage, "smartvitrines")):
        for name in sorted(files):
            full = os.path.join(root, name)
            zf.write(full, os.path.relpath(full, stage))
PY
fi

echo ">> OK: $OUT (versão ${VERSION})"
