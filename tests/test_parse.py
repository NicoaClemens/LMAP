import unittest

from tools.parse import _parse_svg_path


class ParseSvgPathTests(unittest.TestCase):
    def test_uppercase_commands_use_absolute_coordinates(self):
        vectors = _parse_svg_path("M10,20 L30,40 H50 V60 Z")

        self.assertEqual(len(vectors), 4)
        self.assertEqual((vectors[0].x1, vectors[0].y1, vectors[0].x2, vectors[0].y2), (10.0, 20.0, 30.0, 40.0))
        self.assertEqual((vectors[1].x1, vectors[1].y1, vectors[1].x2, vectors[1].y2), (30.0, 40.0, 50.0, 40.0))
        self.assertEqual((vectors[2].x1, vectors[2].y1, vectors[2].x2, vectors[2].y2), (50.0, 40.0, 50.0, 60.0))
        self.assertEqual((vectors[3].x1, vectors[3].y1, vectors[3].x2, vectors[3].y2), (50.0, 60.0, 10.0, 20.0))

    def test_lowercase_commands_use_relative_coordinates(self):
        vectors = _parse_svg_path("M10,20 l5,10 h5 v5 z")

        self.assertEqual(len(vectors), 4)
        self.assertEqual((vectors[0].x1, vectors[0].y1, vectors[0].x2, vectors[0].y2), (10.0, 20.0, 15.0, 30.0))
        self.assertEqual((vectors[1].x1, vectors[1].y1, vectors[1].x2, vectors[1].y2), (15.0, 30.0, 20.0, 30.0))
        self.assertEqual((vectors[2].x1, vectors[2].y1, vectors[2].x2, vectors[2].y2), (20.0, 30.0, 20.0, 35.0))
        self.assertEqual((vectors[3].x1, vectors[3].y1, vectors[3].x2, vectors[3].y2), (20.0, 35.0, 10.0, 20.0))


if __name__ == "__main__":
    unittest.main()
