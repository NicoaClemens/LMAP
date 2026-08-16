#!/usr/bin/env python3

from __future__ import annotations

import argparse
import sqlite3
import sys
import time
import uuid
from pathlib import Path

TOOLS_DIR = Path(__file__).resolve().parent
REPO_ROOT = TOOLS_DIR.parent
if str(REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(REPO_ROOT))
if str(TOOLS_DIR) not in sys.path:
    sys.path.insert(0, str(TOOLS_DIR))

try:
    from tools.config import load_project_config, resolve_data_directory
    from tools.pack import read_region
    from tools.parse import from_svg
except ImportError: 
    from config import load_project_config, resolve_data_directory
    from pack import read_region
    from parse import from_svg


def ensure_sqlite_db(data_directory: Path) -> Path:
    db_dir = data_directory / "db"
    db_dir.mkdir(parents=True, exist_ok=True)
    db_path = db_dir / "lmap.sqlite"

    with sqlite3.connect(db_path) as conn:
        conn.execute(
            "CREATE TABLE IF NOT EXISTS domain_metadata ("
            "domain_id INTEGER PRIMARY KEY, "
            "name TEXT, "
            "path TEXT NOT NULL, "
            "updated_at INTEGER NOT NULL"
            ")"
        )
        conn.execute(
            "CREATE TABLE IF NOT EXISTS region_metadata ("
            "uuid TEXT PRIMARY KEY, "
            "domain_id INTEGER NOT NULL, "
            "name TEXT, "
            "layer TEXT, "
            "path TEXT NOT NULL, "
            "updated_at INTEGER NOT NULL"
            ")"
        )
        conn.execute(
            "CREATE TABLE IF NOT EXISTS object_metadata ("
            "uuid TEXT PRIMARY KEY, "
            "cutsout TEXT NOT NULL DEFAULT '0', "
            "name TEXT, "
            "fill TEXT, "
            "layer TEXT, "
            "updated_at INTEGER NOT NULL"
            ")"
        )

    return db_path


def build_svg(
    svg_path: str | Path,
    *,
    domain_id: int = 0,
    name: str = "top-level",
    layer: str = "",
    project_root: Path | None = None,
    data_dir: str | None = None,
) -> dict:
    input_path = Path(svg_path).expanduser().resolve()
    if not input_path.is_file():
        raise FileNotFoundError(f"SVG file not found: {input_path}")

    root = project_root or input_path.parent.parent if input_path.parent.name == "tools" else input_path.parent
    project_root = root if (root / "project.yaml").exists() else Path(__file__).resolve().parent.parent

    config = load_project_config(project_root)
    data_directory = resolve_data_directory(project_root, data_dir or config.get("data", {}).get("directory"))

    domain_dir = data_directory / str(domain_id)
    domain_dir.mkdir(parents=True, exist_ok=True)

    db_path = ensure_sqlite_db(data_directory)
    now = int(time.time())

    region_uuid = uuid.uuid4()
    region_path = domain_dir / f"{region_uuid}.REGION"

    output_files, region_file = from_svg(str(input_path), str(region_path))

    for output_file in output_files:
        try:
            Path(output_file).unlink(missing_ok=True)
        except OSError:
            pass

    with sqlite3.connect(db_path) as conn:
        conn.execute(
            "INSERT INTO domain_metadata (domain_id, name, path, updated_at) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(domain_id) DO UPDATE SET name=excluded.name, path=excluded.path, updated_at=excluded.updated_at",
            (domain_id, name, str(domain_dir), now),
        )

        conn.execute(
            "INSERT INTO region_metadata (uuid, domain_id, name, layer, path, updated_at) VALUES (?, ?, ?, ?, ?, ?) "
            "ON CONFLICT(uuid) DO UPDATE SET domain_id=excluded.domain_id, name=excluded.name, layer=excluded.layer, path=excluded.path, updated_at=excluded.updated_at",
            (region_path.stem, domain_id, name, layer, str(region_path), now),
        )

        for index, segment in enumerate(read_region(str(region_path)), start=1):
            segment_uuid = segment.get("uuid")
            if isinstance(segment_uuid, (bytes, bytearray)):
                uuid_hex = segment_uuid.hex()
            else:
                uuid_hex = str(segment_uuid)

            conn.execute(
                "INSERT INTO object_metadata (uuid, cutsout, name, fill, layer, updated_at) VALUES (?, ?, ?, ?, ?, ?) "
                "ON CONFLICT(uuid) DO UPDATE SET cutsout=excluded.cutsout, name=excluded.name, fill=excluded.fill, layer=excluded.layer, updated_at=excluded.updated_at",
                (
                    uuid_hex,
                    "0",
                    f"{name or 'layer'}:{index}",
                    None,
                    layer or "default",
                    now,
                ),
            )

    return {
        "project_root": str(project_root),
        "data_dir": str(data_directory),
        "db": str(db_path),
        "domain_dir": str(domain_dir),
        "region": str(region_file),
        "output_files": [],
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build LMAP REGION data from an SVG input file.")
    parser.add_argument("svg", help="path to the input SVG file")
    parser.add_argument("--id", type=int, default=0, help="domain id to store the generated REGION under")
    parser.add_argument("--name", default="top-level", help="logical map name to record with the generated layer")
    parser.add_argument("--layer", default="", help="layer identifier or label to record with the generated file")
    parser.add_argument("--project-root", default=None, help="override the project root (defaults to repo root)")
    parser.add_argument("--data-dir", default=None, help="override the data directory from project.yaml")
    args = parser.parse_args(argv)

    try:
        result = build_svg(
            args.svg,
            domain_id=args.id,
            name=args.name,
            layer=args.layer,
            project_root=Path(args.project_root).resolve() if args.project_root else None,
            data_dir=args.data_dir,
        )
    except Exception as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    print(f"Built LMAP region: {result['region']}")
    if args.name:
        print(f"Name: {args.name}")
    if args.layer:
        print(f"Layer: {args.layer}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
