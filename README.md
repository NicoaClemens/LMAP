# LMAP

[![Code style: black](https://img.shields.io/badge/code%20style-black-000000.svg)](https://github.com/psf/black)

## Quickstart:

### Backend

1. copy `project.yaml.example` to `project.yaml` and edit relevant fields
2. create at least one top level layer using `python -m tools.build your-svg.svg --id=0 --name="top-level" --layer="your_layer_name"`
3. this layer should automatically be created as a REGION file in `/data/0/<uuid>.REGION`

### Server

1. create user via `php auth/create_user.php <username> <password>`
2. `php -S localhost:8000` for quickstart, alternatively apache

## Concept

- one large map, whose aspect ratio is inferred from the region bounds being viewed
- stored primarily as vectors using VECS/REGION files
- spatial pyramid/LOD ; One set of REGION files for max zoomed out, then support lots of sub-sets for zooming in at different levels and places
- VECS = 1 (enclosed) object; outlines of a continent, lake, borders, etc (polygon). coordinates are relative to the overarching REGION datastructure
- REGION = many VECS corresponding to one regionlayer (from x1/y1 to x2/y2, zoomed in at a relevant level, displaying the layer XYZ (for example, political-borders-01))
- DOMAIN = directory of many REGION files to represent one zoomed in section; directory with domain UUID, top level domain always id 0
- no "child region"; one REGION per level of zoom @ coordinate
- sqlite for information about ever VECS object, something like

```sql
CREATE TABLE object_metadata (
    uuid TEXT PRIMARY KEY, -- map to UUID in VECS
    cutsout TEXT, -- 0 by default
    name TEXT,
    fill TEXT, -- color
    layer TEXT,       -- e.g. "terrain", "political", "poi" - future-proofing
    updated_at INTEGER
);
```

- sqlite for information about every DOMAIN

```sql
(TODO)
```

- TODO: cutout informations

- php webviewer
- basic login, user created from cmd
- sqlite3 for user data

- landmarks table; does not correspond to any VECS object, simply point in space

```sql
CREATE TABLE landmarks (
uuid TEXT PRIMARY KEY,
name TEXT,
x REAL, -- normalized [0,1]
y REAL,
type TEXT, -- "city", "village", "ruin", "poi"
min_zoom REAL,
updated_at INTEGER
);
```

- all coordinates normalized to [0, 1]

- HOW THE HELL TO DO HEIGHTMAPS?? Contour lines??

- project.yaml for global information

- user settings in local browser cache, not on server

- export to svg/png eventually? then via python probably

## Project structure

```text
LMAP
├── data
│   ├── db
│   │   └── lmap.sqlite
│   ├── 0
│   │   ├── <uuid>.REGION
│   │   └── <uuid>.REGION
│   └── <uuid>
│       └── <uuid>.REGION
├── tools
│   └── build.py
├── auth
├── assets
│   └── lmap.css
├── windows
│   ├── edit_controls.php
│   ├── header.php
│   ├── layers.php
│   └── window.php
├── index.php
└── project.yaml

```

## Architecture

This project is split into two layers, and they stay pretty cleanly separated: the web layer is PHP, the data layer is Python.

- PHP handles the browser, auth, sessions, and the UI shell. `index.php` loads the current region, `auth/*.php` handles login and user creation, and `windows/*.php` renders the viewer chrome.
- Python handles the source of truth for map data. `tools/parse.py` turns SVG paths into vector segments, `tools/pack.py` serializes them into `VECS` and `REGION` files, and `tools/build.py` writes the generated region files into `data/` while updating sqlite metadata.
- the actual editing workflow should follow the same pattern: the browser sends a structured edit operation, PHP validates/auths it, then Python loads the region, applies the change, reserializes it, and writes it back atomically. No raw byte-level edits in PHP; this keeps the format portable and avoids corruption.
- this is intentionally a data-first architecture: what the user is editing is not a “file-like” object in the browser, but a logical region made of vector segments, bounds, and metadata. That makes it easier to port the same logic into other tools later without rewriting the whole web app.

## VECS file/datastructure

### Header

- 4 byte string "VECS" magic
- 16 bytes uuid
- uint16 VERSION
- uint16 FLAGS
- uint32 count
- float32 boundary_x1
- float32 boundary_y1
- float32 boundary_x2
- float32 boundary_y2
- float32 scale

scale can technically be inferred from boundary coordinates of this and higher layouts but

### Payload

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

### Header

- 4 byte string "REGN" magic
- uint32 count

### Payload

- repeated VECS datastructs

Each VECS datastruct in the REGION file shares the same header values apart from `uuid` and `count`. In other words, they share the same version, flags, boundary values and scale; each entry gets its own unique uuid, and only `count` differs from entry to entry.

## Regex storage

- check for curve commands in svg file: `<path\b[^>]*\bd=["'][^"']*[CSQTAcsqta]\s*[-+]?(?:\d+(?:\.\d*)?|\.\d+)`

## PHP webviewer / auth

- basic PHP viewer is located in `index.php`
- login page is in `auth/login.php`
- user creation is command-line only via `auth/create_user.php`
- users are stored in a SQLite file under `data/db/`
- all auth state is kept in an active session; unauthenticated users are redirected to the login page

Create a user from the command line:

```bash
php auth/create_user.php admin secret123
```

Then open the app in the browser and log in with that username and password.
