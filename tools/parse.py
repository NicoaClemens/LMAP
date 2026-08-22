import re
from pathlib import Path
from xml.etree import ElementTree as ET

import numpy as np

try:
    from tools.pack import write, write_region
    from tools.data import Vector
except ImportError:  # pragma: no cover - allows direct execution from tools/
    from pack import write, write_region
    from data import Vector

_CURVE_COMMAND_RE = re.compile(
    r'''<path\b[^>]*\bd=["'][^"']*[CSQTAcsqta]\s*[-+]?(?:\d+(?:\.\d*)?|\.\d+)'''
)
_PATH_TOKEN_RE = re.compile(r"[MmLlHhVvZz]|[-+]?(?:\d*\.\d+|\d+\.\d*|\d+)")


def _parse_svg_path(path_data: str):
    tokens = _PATH_TOKEN_RE.findall(path_data)
    if not tokens:
        return []

    vectors = []
    current_x = 0.0
    current_y = 0.0
    start_x = 0.0
    start_y = 0.0
    command = None
    i = 0

    def read_number_pair(index):
        if index + 1 >= len(tokens):
            raise ValueError(f"Incomplete SVG path segment in: {path_data}")
        x = float(tokens[index])
        y = float(tokens[index + 1])
        return index + 2, x, y

    while i < len(tokens):
        token = tokens[i]

        if token.isalpha():
            command = token
            i += 1
            if command in {"Z", "z"}:
                vectors.append(
                    Vector(
                        np.float32(current_x),
                        np.float32(current_y),
                        np.float32(start_x),
                        np.float32(start_y),
                    )
                )
                current_x, current_y = start_x, start_y
                continue
            continue

        if command is None:
            command = "L"

        if command in {"M", "m"}:
            index = i
            while index < len(tokens) and not tokens[index].isalpha():
                index, x, y = read_number_pair(index)
                x = x if command == "M" else current_x + x
                y = y if command == "M" else current_y + y
                if index - 2 == i:
                    current_x, current_y = x, y
                    start_x, start_y = current_x, current_y
                    i = index
                else:
                    vectors.append(
                        Vector(
                            np.float32(current_x),
                            np.float32(current_y),
                            np.float32(x),
                            np.float32(y),
                        )
                    )
                    current_x, current_y = x, y
                    i = index
            command = "L"
            continue

        if command in {"L", "l"}:
            while i < len(tokens) and not tokens[i].isalpha():
                i, x, y = read_number_pair(i)
                x = x if command == "L" else current_x + x
                y = y if command == "L" else current_y + y
                vectors.append(
                    Vector(
                        np.float32(current_x),
                        np.float32(current_y),
                        np.float32(x),
                        np.float32(y),
                    )
                )
                current_x, current_y = x, y
            continue

        if command in {"H", "h"}:
            while i < len(tokens) and not tokens[i].isalpha():
                value = float(tokens[i])
                x = value if command == "H" else current_x + value
                y = current_y
                vectors.append(
                    Vector(
                        np.float32(current_x),
                        np.float32(current_y),
                        np.float32(x),
                        np.float32(y),
                    )
                )
                current_x = x
                i += 1
            continue

        if command in {"V", "v"}:
            while i < len(tokens) and not tokens[i].isalpha():
                value = float(tokens[i])
                x = current_x
                y = value if command == "V" else current_y + value
                vectors.append(
                    Vector(
                        np.float32(current_x),
                        np.float32(current_y),
                        np.float32(x),
                        np.float32(y),
                    )
                )
                current_y = y
                i += 1
            continue

        if command in {"Z", "z"}:
            vectors.append(
                Vector(
                    np.float32(current_x),
                    np.float32(current_y),
                    np.float32(start_x),
                    np.float32(start_y),
                )
            )
            current_x, current_y = start_x, start_y
            i += 1
            continue

        raise ValueError(f"Unsupported SVG path command: {command!r} in {path_data!r}")

    return vectors


def _bounds_for(vecs):
    if not vecs:
        raise ValueError("No vectors available to compute bounds")
    x_values = [coord for vector in vecs for coord in (vector.x1, vector.x2)]
    y_values = [coord for vector in vecs for coord in (vector.y1, vector.y2)]
    return Vector(
        np.float32(min(x_values)),
        np.float32(min(y_values)),
        np.float32(max(x_values)),
        np.float32(max(y_values)),
    )


def from_svg(filename_in, filename_out):
    with open(filename_in, "r", encoding="utf-8") as svg_file:
        svg_text = svg_file.read()

    if _CURVE_COMMAND_RE.search(svg_text):
        raise ValueError(
            "SVG contains a curved path command (C/S/Q/T/A). "
            "Curved paths are not supported by this conversion step."
        )

    root = ET.fromstring(svg_text)
    path_vectors = []

    for element in root.iter():
        tag = element.tag.rsplit("}", 1)[-1]
        if tag != "path":
            continue
        path_data = element.attrib.get("d")
        if not path_data:
            continue
        vectors = _parse_svg_path(path_data)
        if vectors:
            path_vectors.append(vectors)

    if not path_vectors:
        raise ValueError(f"No line vectors could be parsed from SVG file: {filename_in}")

    output_parent = Path(filename_out).parent
    if str(filename_out).lower().endswith(".region"):
        region_dir = output_parent
        region_path = Path(filename_out)
    elif output_parent.name == ".region":
        region_dir = output_parent
        region_path = output_parent / "REGION"
    else:
        region_dir = output_parent / ".region"
        region_path = region_dir / "REGION"

    region_dir.mkdir(parents=True, exist_ok=True)

    shared_dim = _bounds_for([v for vectors in path_vectors for v in vectors])
    shared_scale = np.float32(1.0)
    output_files = []

    for index, vectors in enumerate(path_vectors, start=1):
        vec_filename = region_dir / f"path_{index:04d}.vecs"
        write(vec_filename, vectors, _bounds_for(vectors), shared_scale)
        output_files.append(vec_filename)

    write_region(str(region_path), path_vectors, shared_dim, shared_scale)
    return output_files, str(region_path)
