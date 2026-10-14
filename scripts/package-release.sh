#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$ROOT_DIR/build"
PACKAGE_DIR="$BUILD_DIR/npcink-ai-client-adapter"
ZIP_PATH="$BUILD_DIR/npcink-ai-client-adapter.zip"

mkdir -p "$BUILD_DIR"
rm -f "$ZIP_PATH"
rsync -a --delete --exclude-from="$ROOT_DIR/.distignore" "$ROOT_DIR/" "$PACKAGE_DIR/"

# Build a byte-stable ZIP: source checkout mtimes, user ids and permissions must
# not affect the release checksum. SOURCE_DATE_EPOCH can pin a reviewed epoch.
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-946684800}" \
python3 - "$PACKAGE_DIR" "$ZIP_PATH" <<'PY'
import os
import stat
import sys
import time
import zipfile
from pathlib import Path

root = Path(sys.argv[1])
output = Path(sys.argv[2])
epoch = int(os.environ.get('SOURCE_DATE_EPOCH', '946684800'))
date = time.gmtime(epoch)
date_time = (max(1980, date.tm_year), date.tm_mon, date.tm_mday, date.tm_hour, date.tm_min, date.tm_sec)

entries = [root]
entries.extend(sorted(root.rglob('*'), key=lambda path: path.relative_to(root).as_posix()))

with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in entries:
        relative = Path(root.name) / path.relative_to(root)
        is_link = path.is_symlink()
        is_dir = path.is_dir() and not is_link
        name = relative.as_posix() + ('/' if is_dir else '')
        info = zipfile.ZipInfo(name, date_time=date_time)
        info.create_system = 3
        if is_dir:
            info.external_attr = (stat.S_IFDIR | 0o755) << 16 | 0x10
            info.compress_type = zipfile.ZIP_STORED
            archive.writestr(info, b'')
            continue
        if is_link:
            data = os.readlink(path).encode('utf-8')
            info.external_attr = (stat.S_IFLNK | 0o777) << 16
        else:
            data = path.read_bytes()
            info.external_attr = (stat.S_IFREG | 0o644) << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(info, data)
PY

printf 'Built reproducible release package: %s\n' "$ZIP_PATH"
