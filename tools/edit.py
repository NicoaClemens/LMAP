from __future__ import annotations

import hashlib
import json
import os
import struct
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from tools.data import Vector
from tools.pack import read_region


@dataclass
class VecSegment:
    uuid: str
    version: int = 0
    flags: int = 0
    vectors: list[Vector] = field(default_factory=list)
    bounds: tuple[float, float, float, float] = (0.0, 0.0, 0.0, 0.0)

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> "VecSegment":
        vectors = [
            Vector(float(v["x1"]), float(v["y1"]), float(v["x2"]), float(v["y2"]))
            for v in payload.get("vectors", [])
        ]
        return cls(
            uuid=str(payload.get("uuid") or ""),
            version=int(payload.get("version", 0)),
            flags=int(payload.get("flags", 0)),
            vectors=vectors,
            bounds=_bounds_for(vectors),
        )

    def to_payload(self) -> dict[str, Any]:
        return {
            "uuid": self.uuid,
            "version": self.version,
            "flags": self.flags,
            "bounds": {
                "x1": self.bounds[0],
                "y1": self.bounds[1],
                "x2": self.bounds[2],
                "y2": self.bounds[3],
            },
            "vectors": [
                {"x1": float(v.x1), "y1": float(v.y1), "x2": float(v.x2), "y2": float(v.y2)}
                for v in self.vectors
            ],
        }


@dataclass
class Region:
    segments: list[VecSegment] = field(default_factory=list)

    def to_payload(self) -> dict[str, Any]:
        return {"segments": [segment.to_payload() for segment in self.segments]}


def _bounds_for(vectors: list[Vector]) -> tuple[float, float, float, float]:
    if not vectors:
        return (0.0, 0.0, 0.0, 0.0)

    xs = [coord for vector in vectors for coord in (vector.x1, vector.x2)]
    ys = [coord for vector in vectors for coord in (vector.y1, vector.y2)]
    return (float(min(xs)), float(min(ys)), float(max(xs)), float(max(ys)))


def _vector_from_payload(payload: dict[str, Any] | None) -> Vector:
    data = payload or {}
    return Vector(
        float(data.get("x1", 0.0)),
        float(data.get("y1", 0.0)),
        float(data.get("x2", 0.0)),
        float(data.get("y2", 0.0)),
    )


def _uuid_to_bytes(value: str | bytes | bytearray | None) -> bytes:
    if value is None:
        return hashlib.md5(b"segment").digest()[:16]
    if isinstance(value, (bytes, bytearray)):
        raw = bytes(value)
        if len(raw) == 16:
            return raw
        return hashlib.md5(raw).digest()[:16]

    text = str(value).strip()
    if not text:
        return hashlib.md5(b"segment").digest()[:16]
    if len(text) == 32 and all(ch in "0123456789abcdefABCDEF" for ch in text):
        return bytes.fromhex(text)
    if len(text) == 16:
        return text.encode("ascii")
    return hashlib.md5(text.encode("utf-8")).digest()[:16]


def _canonical_uuid(value: str | bytes | bytearray | None) -> str:
    return _uuid_to_bytes(value).hex()


def _segment_by_uuid(region: Region, segment_uuid: str) -> VecSegment:
    if not segment_uuid:
        raise ValueError("Segment UUID is required")

    for item in region.segments:
        if item.uuid == segment_uuid or _canonical_uuid(item.uuid) == _canonical_uuid(segment_uuid):
            return item

    raise ValueError(f"Segment not found: {segment_uuid}")


def _recompute_segment_bounds(segment: VecSegment) -> None:
    segment.bounds = _bounds_for(segment.vectors)


def load_region(path: str | os.PathLike[str]) -> Region:
    payload = read_region(str(path))
    segments = []
    for item in payload:
        raw_uuid = item.get("uuid")
        if isinstance(raw_uuid, (bytes, bytearray)):
            uuid_value = raw_uuid.hex()
        else:
            uuid_value = str(raw_uuid or "")

        segments.append(
            VecSegment(
                uuid=uuid_value,
                version=int(item.get("version", 0)),
                flags=int(item.get("flags", 0)),
                vectors=[
                    Vector(float(v.x1), float(v.y1), float(v.x2), float(v.y2))
                    for v in item.get("vectors", [])
                ],
                bounds=(
                    float(item["bounds"][0]),
                    float(item["bounds"][1]),
                    float(item["bounds"][2]),
                    float(item["bounds"][3]),
                ),
            )
        )
    return Region(segments=segments)


def write_region(path: str | os.PathLike[str], region: Region) -> str:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)

    region_bytes = bytearray()
    region_bytes.extend(struct.pack("<4sI", b"REGN", len(region.segments)))

    for segment in region.segments:
        _recompute_segment_bounds(segment)
        uuid_bytes = _uuid_to_bytes(segment.uuid)
        header = struct.pack(
            "<4s16sHHIfffff",
            b"VECS",
            uuid_bytes,
            segment.version,
            segment.flags,
            len(segment.vectors),
            segment.bounds[0],
            segment.bounds[1],
            segment.bounds[2],
            segment.bounds[3],
            1.0,
        )
        region_bytes.extend(header)
        for vector in segment.vectors:
            region_bytes.extend(struct.pack("<ffff", vector.x1, vector.y1, vector.x2, vector.y2))

    with open(path, "wb") as handle:
        handle.write(region_bytes)

    return str(path)


def apply_edit(region: Region, edit: dict[str, Any]) -> Region:
    if not isinstance(edit, dict):
        raise ValueError("Edit payload must be a dictionary")

    if "operations" in edit and isinstance(edit["operations"], list):
        payloads = edit["operations"]
        for payload in payloads:
            region = apply_edit(region, payload)
        return region

    op = str(edit.get("op") or "").strip()
    if not op:
        raise ValueError("Edit operation is required")

    if op == "insert_vector":
        segment = _segment_by_uuid(region, str(edit.get("segment_uuid") or ""))
        index = min(max(int(edit.get("index", len(segment.vectors))), 0), len(segment.vectors))
        vector = _vector_from_payload(edit.get("vector"))
        segment.vectors.insert(index, vector)
        _recompute_segment_bounds(segment)
        return region

    if op == "delete_vector":
        segment = _segment_by_uuid(region, str(edit.get("segment_uuid") or ""))
        index = int(edit.get("index", 0))
        if index < 0:
            index = 0
        if index >= len(segment.vectors):
            index = len(segment.vectors) - 1
        del segment.vectors[index]
        _recompute_segment_bounds(segment)
        return region

    if op == "update_vector":
        segment = _segment_by_uuid(region, str(edit.get("segment_uuid") or ""))
        index = int(edit.get("index", 0))
        if index < 0:
            index = 0
        if index >= len(segment.vectors):
            index = len(segment.vectors) - 1
        segment.vectors[index] = _vector_from_payload(edit.get("vector"))
        _recompute_segment_bounds(segment)
        return region

    if op == "move_vector":
        segment = _segment_by_uuid(region, str(edit.get("segment_uuid") or ""))
        from_index = int(edit.get("from_index", 0))
        to_index = int(edit.get("to_index", from_index))
        if not (0 <= from_index < len(segment.vectors)):
            raise ValueError(f"from_index out of range: {from_index}")
        item = segment.vectors.pop(from_index)
        if to_index < 0:
            to_index = 0
        if to_index > len(segment.vectors):
            to_index = len(segment.vectors)
        segment.vectors.insert(to_index, item)
        _recompute_segment_bounds(segment)
        return region

    raise ValueError(f"Unsupported edit op: {op}")


def apply_edits(region: Region, edits: list[dict[str, Any]]) -> Region:
    for edit in edits:
        region = apply_edit(region, edit)
    return region


def _load_edit_payload(raw: str | None, from_file: str | None) -> dict[str, Any] | list[dict[str, Any]]:
    if from_file:
        with open(from_file, "r", encoding="utf-8") as handle:
            raw = handle.read()
    if raw is None:
        raise ValueError("No edit JSON provided")
    payload = json.loads(raw)
    if isinstance(payload, list):
        return payload
    return payload


def main(argv: list[str] | None = None) -> int:
    import argparse

    parser = argparse.ArgumentParser(description="LMAP region edit API")
    parser.add_argument("--region", required=True, help="path to .REGION file")
    parser.add_argument("--edit-json", help="JSON edit payload")
    parser.add_argument("--edit-file", help="path to a JSON file containing an edit payload")
    args = parser.parse_args(argv)

    if not args.edit_json and not args.edit_file:
        if sys.stdin.isatty():
            parser.error("Provide --edit-json or --edit-file")
        args.edit_json = sys.stdin.read()

    region = load_region(args.region)
    payload = _load_edit_payload(args.edit_json, args.edit_file)
    if isinstance(payload, list):
        updated = apply_edits(region, payload)
    else:
        updated = apply_edit(region, payload)
    write_region(args.region, updated)
    print(json.dumps({"ok": True, "segments": len(updated.segments)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
