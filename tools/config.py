from __future__ import annotations

from pathlib import Path


def _strip_inline_yaml_comment(value: str) -> str:
    in_single = False
    in_double = False
    for idx, char in enumerate(value):
        if char == "'" and not in_double:
            in_single = not in_single
        elif char == '"' and not in_single:
            in_double = not in_double
        elif char == "#" and not in_single and not in_double:
            return value[:idx].rstrip()
    return value.rstrip()


def _parse_simple_yaml_scalar(raw: str):
    value = _strip_inline_yaml_comment(raw).strip()
    if not value:
        return ""
    if value.startswith('"') and value.endswith('"'):
        return value[1:-1]
    if value.startswith("'") and value.endswith("'"):
        return value[1:-1]
    if value.lower() == "true":
        return True
    if value.lower() == "false":
        return False
    if value.lower() in {"null", "~"}:
        return None
    try:
        return int(value)
    except ValueError:
        pass
    try:
        return float(value)
    except ValueError:
        pass
    return value


def load_project_config(project_root: Path) -> dict:
    config_path = project_root / "project.yaml"
    if not config_path.is_file():
        return {}

    config: dict = {}
    section = None

    for raw_line in config_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.rstrip()
        stripped = line.strip()

        if not stripped or stripped.startswith("#"):
            continue

        if not line.startswith(" ") and not line.startswith("\t"):
            if stripped.endswith(":"):
                section = stripped[:-1]
                config.setdefault(section, {})
            else:
                section = None
            continue

        if section is None or ":" not in stripped:
            continue

        key, _, value = stripped.partition(":")
        key = key.strip()
        value = _strip_inline_yaml_comment(value).strip()
        if value:
            config[section][key] = _parse_simple_yaml_scalar(value)
        else:
            config[section][key] = {}

    return config


def resolve_data_directory(project_root: Path, configured_directory: str | None = None) -> Path:
    if configured_directory:
        path = Path(configured_directory)
        if not path.is_absolute():
            path = project_root / path
        return path.resolve()
    return (project_root / "data").resolve()
