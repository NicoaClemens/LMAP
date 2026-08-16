# LMAP

[![Code style: black](https://img.shields.io/badge/code%20style-black-000000.svg)](https://github.com/psf/black)

## Concept

- one large map, with aspect ratio specified in project.yaml/map/aspect_ratio
- stored primarily as vectors using VECS/REGION files
- spatial pyramid/LOD ; One main REGION file for max zoomed out, then support lots of sub-files for zooming in at different levels and places
- VECS = 1 (enclosed) object; outlines of a continent, lake, borders, etc (polygon). coordinates are relative to the overarching REGION datastructure
- REGION = many VECS corresponding to one region (from x1/y1 to x2/y2, zoomed in at a relevant level)
- no "child region"; one REGION per level of zoom @ coordinate
- sqlite for information about ever VECS object that isn't a cutout, something like

```sql
CREATE TABLE object_metadata (
    uuid TEXT PRIMARY KEY, -- map to UUID in VECS
    name TEXT,
    fill TEXT, -- color
    layer TEXT,       -- e.g. "terrain", "political", "poi" - future-proofing
    updated_at INTEGER
);
```

- php webviewer
- basic login, user created from cmd
- sqlite3 for user data

- `landmarks.json` or similar that contains fixed coordinates for POIs such as cities

- all coordinates normalized to [0, 1]

- HOW THE HELL TO DO HEIGHTMAPS?? Contour lines??

- project.yaml for global information

## VECS file/datastructure

Header:

- 4 byte string "VECS" magic
- 16 bytes uuid
- uint8 VERSION
- uint8 FLAGS
- 16 bytes cutout_target_id <- all zero by default
- uint16 count
- float32 boundary_x1
- float32 boundary_y1
- float32 boundary_x2
- float32 boundary_y2
- float32 scale

scale can technically be inferred from boundary coordinates of this and higher layouts but

Payload

- float32 x_1
- float32 y_1
- float32 x_2
- float32 y_2

times `count`

### FLAGS MAP

| ID  | Meaning |
| --- | ------- |
| 0   | cutout  |

## REGION file

Header:

- 4 byte string "REGN" magic
- uint32 count

Payload

- repeated VECS datastructs

Each VECS datastruct in the REGION file shares the same header values apart from `uuid` and `count`. In other words, they share the same version, flags, boundary values and scale; each entry gets its own unique uuid, and only `count` differs from entry to entry.

## Regex storage

- check for curve commands in svg file: `<path\b[^>]*\bd=["'][^"']*[CSQTAcsqta]\s*[-+]?(?:\d+(?:\.\d*)?|\.\d+)`
