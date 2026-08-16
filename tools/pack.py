import struct
import uuid

import numpy as np

try:
    from tools.data import Vector
except ImportError:  # pragma: no cover - allows direct execution from tools/
    from data import Vector

__HEADER_FORMAT = "<4s16sHHIfffff"
__HEADER_SIZE = struct.calcsize(__HEADER_FORMAT)

__REGION_HEADER_FORMAT = "<4sI"
__REGION_HEADER_SIZE = struct.calcsize(__REGION_HEADER_FORMAT)

__RECORD_FORMAT = "<ffff"
__RECORD_SIZE = struct.calcsize(__RECORD_FORMAT)

__MAGIC = b"VECS"
__REGION_MAGIC = b"REGN"
__VERSION = 0
__FLAGS = 0


def _make_uuid_bytes():
    return uuid.uuid4().hex[:16].encode("ascii")


def _normalized_dim(vecs, dim=None):
    vecs = list(vecs)
    if dim is None:
        if not vecs:
            raise ValueError("Cannot determine bounds for an empty vector set")
        x_values = [coord for value in vecs for coord in (value.x1, value.x2)]
        y_values = [coord for value in vecs for coord in (value.y1, value.y2)]
        dim = Vector(
            np.float32(min(x_values)),
            np.float32(min(y_values)),
            np.float32(max(x_values)),
            np.float32(max(y_values)),
        )
    return dim


def pack(vecs):
    return b"".join(struct.pack(__RECORD_FORMAT, v.x1, v.y1, v.x2, v.y2) for v in vecs)


def write(filename: str, vecs, dim: Vector, scale: np.float32, uuid_value: bytes | None = None):
    vecs = list(vecs)
    uuid_value = uuid_value or _make_uuid_bytes()
    header = struct.pack(
        __HEADER_FORMAT,
        __MAGIC,
        uuid_value,
        __VERSION,
        __FLAGS,
        len(vecs),
        dim.x1,
        dim.y1, 
        dim.x2,
        dim.y2,
        scale,
    )

    with open(filename, "wb") as f:
        f.write(header)
        f.write(pack(vecs))

    return uuid_value


def write_region(filename: str, vecs_by_path, dim: Vector, scale: np.float32, uuid_value: bytes | None = None):
    dim = _normalized_dim([v for path in vecs_by_path for v in path], dim)

    with open(filename, "wb") as f:
        f.write(struct.pack(__REGION_HEADER_FORMAT, __REGION_MAGIC, len(vecs_by_path)))

        for vecs in vecs_by_path:
            vecs = list(vecs)
            segment_uuid = _make_uuid_bytes()
            header = struct.pack(
                __HEADER_FORMAT,
                __MAGIC,
                segment_uuid,
                __VERSION,
                __FLAGS,
                len(vecs),
                dim.x1,
                dim.y1,
                dim.x2,
                dim.y2,
                scale,
            )
            f.write(header)
            f.write(pack(vecs))

    return [
        struct.pack(__HEADER_FORMAT, __MAGIC, _make_uuid_bytes(), __VERSION, __FLAGS, len(list(vecs)), dim.x1, dim.y1, dim.x2, dim.y2, scale)
        for vecs in vecs_by_path
    ]


def read(filename):
    vecs = []
    with open(filename, "rb") as f:
        header_data = f.read(__HEADER_SIZE)

        if len(header_data) != __HEADER_SIZE:
            raise ValueError("File is too small to contain a valid header")

        magic, uuid_value, version, flags, count, b_x1, b_y1, b_x2, b_y2, scale = (
            struct.unpack(__HEADER_FORMAT, header_data)
        )

        if magic != b"VECS":
            raise ValueError("Not a VECS file")

        for _ in range(count):
            data = f.read(__RECORD_SIZE)
            if len(data) != __RECORD_SIZE:
                raise ValueError("VECS payload is truncated")
            x1, y1, x2, y2 = struct.unpack(__RECORD_FORMAT, data)
            vecs.append(Vector(x1, y1, x2, y2))

    return vecs, magic, uuid_value, version, flags, count, b_x1, b_y1, b_x2, b_y2, scale


def read_region(filename):
    groups = []
    with open(filename, "rb") as f:
        region_header = f.read(__REGION_HEADER_SIZE)
        if len(region_header) != __REGION_HEADER_SIZE:
            raise ValueError("File is too small to contain a valid REGION header")

        region_magic, count = struct.unpack(__REGION_HEADER_FORMAT, region_header)
        if region_magic != __REGION_MAGIC:
            raise ValueError("Not a REGION file")

        for _ in range(count):
            header_data = f.read(__HEADER_SIZE)
            if len(header_data) != __HEADER_SIZE:
                raise ValueError("REGION payload is truncated")

            magic, uuid_value, version, flags, sub_count, b_x1, b_y1, b_x2, b_y2, scale = (
                struct.unpack(__HEADER_FORMAT, header_data)
            )

            if magic != __MAGIC:
                raise ValueError("REGION entry is not a VECS segment")

            vecs = []
            for _ in range(sub_count):
                data = f.read(__RECORD_SIZE)
                if len(data) != __RECORD_SIZE:
                    raise ValueError("VECS segment is truncated")
                x1, y1, x2, y2 = struct.unpack(__RECORD_FORMAT, data)
                vecs.append(Vector(x1, y1, x2, y2))

            groups.append(
                {
                    "uuid": uuid_value,
                    "version": version,
                    "flags": flags,
                    "count": sub_count,
                    "bounds": (b_x1, b_y1, b_x2, b_y2),
                    "scale": scale,
                    "vectors": vecs,
                }
            )

    return groups
