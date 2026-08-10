#!/usr/bin/env bash
# Build the distributable zip for the Wisdom AI Assistant.
#
#  Two things the panel is strict about (both read from the panel source):
#
#   1. The **zip filename decides the folder name**. PluginService checks for
#      "<zip basename>/plugin.json" inside the archive; if found it extracts to
#      plugins/, otherwise it extracts the contents into plugins/<zip basename>/.
#      Either way the name must be `concierge` — it has to match `id`.
#
#   2. `meta` must not ship. It is the panel's own install state (status,
#      status_message); shipping it hands your local state to every installer.
#      The repo is already clean — the Hub rejects a plugin.json that carries it —
#      so the strip below is a guard, not the main line of defence.
#
#  Usage: bash scripts/build-release.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ID="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["id"])' "${ROOT}/plugin.json")"
VERSION="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' "${ROOT}/plugin.json")"

STAGE="$(mktemp -d)"
DEST="${STAGE}/${ID}"
mkdir -p "${DEST}"

# Ship the plugin itself plus the two files users expect in a source drop.
# Repo scaffolding (update.json, scripts, CONTRIBUTING, git metadata) stays behind.
cp -r "${ROOT}/src" "${ROOT}/resources" "${ROOT}/config" "${ROOT}/database" "${ROOT}/lang" \
   "${ROOT}/plugin.json" "${ROOT}/README.md" "${ROOT}/LICENSE" "${DEST}/"

# 배포 환경 지식은 운영자마다 다르다 — 우리 것을 남에게 실어 보내지 않는다.
rm -rf "${DEST}/resources/knowledge"
# egg 덤프는 카탈로그 검증용 로컬 스냅샷이다.
rm -f "${DEST}/resources/catalog/eggs.json"

python3 - "${DEST}/plugin.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data.pop('meta', None)
json.dump(data, open(path, 'w'), indent=4, ensure_ascii=False)
open(path, 'a').write('\n')
PY

mkdir -p "${ROOT}/dist"
OUT="${ROOT}/dist/${ID}.zip"
rm -f "${OUT}"
# `zip` 은 없을 수 있다(이 환경에도 없었다) — 표준 라이브러리로 만든다.
python3 - "${STAGE}" "${ID}" "${OUT}" <<'PY'
import os, sys, zipfile
stage, pid, out = sys.argv[1:4]
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(os.path.join(stage, pid)):
        for f in sorted(files):
            full = os.path.join(root, f)
            z.write(full, os.path.relpath(full, stage))
PY
rm -rf "${STAGE}"

echo "✅ ${OUT}  (v${VERSION})"
python3 -c 'import sys,zipfile; [print(" ", n) for n in zipfile.ZipFile(sys.argv[1]).namelist()]' "${OUT}"
