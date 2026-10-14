#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
first="$(mktemp -t npcink-adapter-package-first.XXXXXX)"
trap 'rm -f "$first"' EXIT

bash "$ROOT_DIR/scripts/package-release.sh" >/dev/null
cp "$ROOT_DIR/build/npcink-ai-client-adapter.zip" "$first"
bash "$ROOT_DIR/scripts/package-release.sh" >/dev/null

if ! cmp -s "$first" "$ROOT_DIR/build/npcink-ai-client-adapter.zip"; then
	echo 'Release package is not byte-for-byte reproducible.' >&2
	exit 1
fi

printf 'Release package reproducibility: ok (%s)\n' "$(shasum -a 256 "$ROOT_DIR/build/npcink-ai-client-adapter.zip" | awk '{print $1}')"
