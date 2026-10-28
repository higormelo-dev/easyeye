"""Self-check of scripts/exam-panorama-stitch.py. Skipped without OpenCV.

Run: <python-with-cv2> -m unittest discover -s tests/Python -v
"""
import os
import tempfile
import unittest

from _synthetic import HAS_CV2, canvas, cv2, run_script

HEIGHT = 700
WIDTH = 900
STEP = 500  # 400 px of overlap between neighbours


@unittest.skipUnless(HAS_CV2, "OpenCV (cv2) not installed")
class ExamPanoramaStitchTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def path(self, name):
        return os.path.join(self.tmp.name, name)

    def write(self, name, image):
        path = self.path(name)
        cv2.imwrite(path, image)

        return path

    def overlapping_crops(self, count):
        wide = canvas(WIDTH + STEP * (count - 1), HEIGHT, seed=21)

        return [self.write(f"p{i}.png", wide[:, i * STEP:i * STEP + WIDTH]) for i in range(count)]

    def test_overlapping_images_stitch_into_a_wider_png(self):
        crops = self.overlapping_crops(3)
        # No extension, like the tempnam() the PHP caller hands over.
        out_path = self.path("panorama-out")

        code, out = run_script("exam-panorama-stitch.py", out_path, *crops)

        self.assertEqual(code, 0)
        self.assertEqual(out, {"ok": True})
        with open(out_path, "rb") as handle:
            self.assertEqual(handle.read(8), b"\x89PNG\r\n\x1a\n")
        result = cv2.imread(out_path)
        self.assertIsNotNone(result)
        self.assertGreater(result.shape[1], WIDTH)

    def test_unwritable_output_path_is_a_write_failure(self):
        crops = self.overlapping_crops(2)

        code, out = run_script("exam-panorama-stitch.py", self.path("missing-dir/panorama-out"), *crops)

        self.assertEqual(code, 0)
        self.assertEqual(out, {"ok": False, "reason": "write_failed"})

    def test_images_without_overlap_fail_with_stitch_failed(self):
        a = self.write("a.png", canvas(WIDTH, HEIGHT, seed=3))
        b = self.write("b.png", canvas(WIDTH, HEIGHT, seed=4))
        out_path = self.path("panorama.png")

        code, out = run_script("exam-panorama-stitch.py", out_path, a, b)

        self.assertEqual(code, 0)
        self.assertFalse(out["ok"], out)
        self.assertTrue(out["reason"].startswith("stitch_failed:"), out)
        self.assertFalse(os.path.exists(out_path))

    def test_unreadable_image_exits_zero_with_prefixed_reason(self):
        good = self.overlapping_crops(1)[0]
        broken = self.path("broken.png")
        with open(broken, "wb") as handle:
            handle.write(b"not an image")

        code, out = run_script("exam-panorama-stitch.py", self.path("panorama.png"), good, broken)

        self.assertEqual(code, 0)
        self.assertFalse(out["ok"])
        self.assertTrue(out["reason"].startswith("unreadable_image:"), out)

    def test_too_few_arguments_is_a_usage_error(self):
        code, out = run_script("exam-panorama-stitch.py", self.path("panorama.png"), self.path("one.png"))

        self.assertEqual(code, 0)
        self.assertEqual(out, {"ok": False, "reason": "usage"})


if __name__ == "__main__":
    unittest.main()
