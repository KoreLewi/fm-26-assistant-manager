#!/usr/bin/env python3
"""Decode and import the committed initial FM26 snapshot."""

from pathlib import Path
import base64
import gzip
import json
import sqlite3
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
ARCHIVE = ROOT / "data" / "saves" / "valencia-2025-26" / "initial_valencia_snapshot_2025-12-22.json.gz.b64"
IMPORTER = ROOT / "scripts" / "import_json.py"
TEMP_JSON = ROOT / ".initial_snapshot.json"


def main() -> None:
    raw = ARCHIVE.read_text(encoding="utf-8").strip()
    decoded = gzip.decompress(base64.b64decode(raw))
    payload = json.loads(decoded.decode("utf-8"))
    TEMP_JSON.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    try:
        subprocess.run([sys.executable, str(IMPORTER), str(TEMP_JSON)], check=True)
    finally:
        TEMP_JSON.unlink(missing_ok=True)


if __name__ == "__main__":
    main()
