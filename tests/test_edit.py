import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from tools.data import Vector
from tools.edit import Region, VecSegment, apply_edit, load_region, write_region


class EditRegionTests(unittest.TestCase):
    def test_insert_vector_updates_segment_and_bounds(self):
        region = Region(
            segments=[
                VecSegment(
                    uuid="segment-1",
                    version=0,
                    flags=0,
                    vectors=[
                        Vector(0.0, 0.0, 1.0, 0.0),
                        Vector(1.0, 0.0, 1.0, 1.0),
                    ],
                )
            ]
        )

        updated = apply_edit(
            region,
            {
                "op": "insert_vector",
                "segment_uuid": "segment-1",
                "index": 1,
                "vector": {"x1": 0.5, "y1": 0.0, "x2": 0.5, "y2": 1.0},
            },
        )

        self.assertEqual(len(updated.segments[0].vectors), 3)
        self.assertEqual(updated.segments[0].vectors[1].x1, 0.5)
        self.assertEqual(updated.segments[0].vectors[1].x2, 0.5)
        self.assertEqual(updated.segments[0].bounds, (0.0, 0.0, 1.0, 1.0))

    def test_delete_and_move_vector(self):
        region = Region(
            segments=[
                VecSegment(
                    uuid="segment-1",
                    version=0,
                    flags=0,
                    vectors=[
                        Vector(0.0, 0.0, 1.0, 0.0),
                        Vector(1.0, 0.0, 1.0, 1.0),
                        Vector(1.0, 1.0, 0.0, 1.0),
                    ],
                )
            ]
        )

        after_delete = apply_edit(
            region,
            {"op": "delete_vector", "segment_uuid": "segment-1", "index": 1},
        )
        self.assertEqual(len(after_delete.segments[0].vectors), 2)

        fresh_region = Region(
            segments=[
                VecSegment(
                    uuid="segment-1",
                    version=0,
                    flags=0,
                    vectors=[
                        Vector(0.0, 0.0, 1.0, 0.0),
                        Vector(1.0, 0.0, 1.0, 1.0),
                        Vector(1.0, 1.0, 0.0, 1.0),
                    ],
                )
            ]
        )

        after_move = apply_edit(
            fresh_region,
            {"op": "move_vector", "segment_uuid": "segment-1", "from_index": 0, "to_index": 2},
        )
        self.assertEqual(after_move.segments[0].vectors[2].x1, 0.0)
        self.assertEqual(after_move.segments[0].vectors[2].y1, 0.0)

    def test_update_vector_rewrites_geometry(self):
        region = Region(
            segments=[
                VecSegment(
                    uuid="segment-1",
                    version=0,
                    flags=0,
                    vectors=[Vector(0.0, 0.0, 1.0, 0.0)],
                )
            ]
        )

        updated = apply_edit(
            region,
            {
                "op": "update_vector",
                "segment_uuid": "segment-1",
                "index": 0,
                "vector": {"x1": 0.5, "y1": 0.5, "x2": 2.0, "y2": 0.5},
            },
        )

        self.assertEqual(updated.segments[0].vectors[0].x1, 0.5)
        self.assertEqual(updated.segments[0].vectors[0].y1, 0.5)
        self.assertEqual(updated.segments[0].bounds, (0.5, 0.5, 2.0, 0.5))

    def test_load_and_write_region_round_trip(self):
        region = Region(
            segments=[
                VecSegment(
                    uuid="segment-1",
                    version=0,
                    flags=0,
                    vectors=[
                        Vector(10.0, 20.0, 30.0, 20.0),
                        Vector(30.0, 20.0, 30.0, 40.0),
                    ],
                )
            ]
        )

        with tempfile.TemporaryDirectory() as tmpdir:
            path = Path(tmpdir) / "test.REGION"
            write_region(path, region)
            loaded = load_region(path)

            self.assertEqual(len(loaded.segments), 1)
            self.assertEqual(len(loaded.segments[0].vectors), 2)
            self.assertEqual(loaded.segments[0].bounds, (10.0, 20.0, 30.0, 40.0))

    def test_module_cli_wrapper(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            path = Path(tmpdir) / "cli.REGION"
            payload = {
                "segments": [
                    {
                        "uuid": "segment-1",
                        "version": 0,
                        "flags": 0,
                        "vectors": [
                            {"x1": 0.0, "y1": 0.0, "x2": 1.0, "y2": 0.0},
                            {"x1": 1.0, "y1": 0.0, "x2": 1.0, "y2": 1.0},
                        ],
                    }
                ]
            }

            with open(path, "wb") as handle:
                handle.write(b"")

            edit = {
                "op": "insert_vector",
                "segment_uuid": "segment-1",
                "index": 1,
                "vector": {"x1": 0.5, "y1": 0.0, "x2": 0.5, "y2": 1.0},
            }

            # create a valid region file first
            write_region(path, Region(segments=[VecSegment(uuid="segment-1", vectors=[
                Vector(0.0, 0.0, 1.0, 0.0),
                Vector(1.0, 0.0, 1.0, 1.0),
            ])]))

            completed = subprocess.run(
                [sys.executable, "-m", "tools.edit", "--region", str(path), "--edit-json", json.dumps(edit)],
                capture_output=True,
                text=True,
                check=False,
            )

            self.assertEqual(completed.returncode, 0, completed.stderr)
            self.assertIn('"ok": true', completed.stdout)


if __name__ == "__main__":
    unittest.main()
