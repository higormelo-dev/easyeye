"""Self-check of scripts/exam-align-detect.py. Skipped without OpenCV.

Run: <python-with-cv2> -m unittest discover -s tests/Python -v
"""
import os
import tempfile
import unittest

from _synthetic import HAS_CV2, canvas, cv2, np, run_script

SIZE = 900


@unittest.skipUnless(HAS_CV2, "OpenCV (cv2) not installed")
class ExamAlignDetectTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def path(self, name):
        return os.path.join(self.tmp.name, name)

    def write(self, name, image):
        path = self.path(name)
        cv2.imwrite(path, image)

        return path

    def shifted_pair(self, shift_x, shift_y, size_b=None):
        big = canvas(SIZE + 400, SIZE + 400, seed=11)
        a = big[200:200 + SIZE, 200:200 + SIZE]
        b = big[200 + shift_y:200 + shift_y + SIZE, 200 + shift_x:200 + shift_x + SIZE]

        if size_b is not None:
            b = cv2.resize(b, (size_b, size_b), interpolation=cv2.INTER_AREA)

        return self.write("a.png", a), self.write("b.png", b)

    def test_known_shift_is_recovered_in_pixels_of_a(self):
        a, b = self.shifted_pair(37, -22)

        code, out = run_script("exam-align-detect.py", a, b)

        self.assertEqual(code, 0)
        self.assertTrue(out["ok"], out)
        self.assertAlmostEqual(out["dx"], 37, delta=2)
        self.assertAlmostEqual(out["dy"], -22, delta=2)
        self.assertGreaterEqual(out["confidence"], 0.3)
        self.assertLessEqual(out["confidence"], 1.0)

    def test_b_with_other_dimensions_is_resized_to_a(self):
        # B has the same content but a smaller raster: dx/dy still come back
        # in pixels of A (the shift of 40 px in A is 20 px in the 450 px B).
        a, b = self.shifted_pair(40, 30, size_b=SIZE // 2)

        code, out = run_script("exam-align-detect.py", a, b)

        self.assertEqual(code, 0)
        self.assertTrue(out["ok"], out)
        self.assertAlmostEqual(out["dx"], 40, delta=3)
        self.assertAlmostEqual(out["dy"], 30, delta=3)

    def test_small_rotation_keeps_the_offset_centered(self):
        # B is A shifted by (30, -18) and rotated 3 degrees about its center:
        # the offset that lays B over A is the displacement of the CENTER, not
        # the raw translation term of the matrix (which drifts by ~|(I-R)c|).
        big = canvas(SIZE + 400, SIZE + 400, seed=11)
        a = big[200:200 + SIZE, 200:200 + SIZE]
        shifted = big[200 - 18:200 - 18 + SIZE, 200 + 30:200 + 30 + SIZE]
        rotation = cv2.getRotationMatrix2D((SIZE / 2, SIZE / 2), 3.0, 1.0)
        b = cv2.warpAffine(shifted, rotation, (SIZE, SIZE), borderMode=cv2.BORDER_REFLECT)

        code, out = run_script("exam-align-detect.py", self.write("a.png", a), self.write("b.png", b))

        self.assertEqual(code, 0)
        self.assertTrue(out["ok"], out)
        self.assertAlmostEqual(out["dx"], 30, delta=3)
        self.assertAlmostEqual(out["dy"], -18, delta=3)

    def test_unrelated_images_do_not_align(self):
        a = self.write("a.png", canvas(SIZE, SIZE, seed=1))
        b = self.write("b.png", canvas(SIZE, SIZE, seed=2))

        code, out = run_script("exam-align-detect.py", a, b)

        self.assertEqual(code, 0)
        self.assertFalse(out["ok"], out)
        self.assertIn(out["reason"], ("no_matches", "low_confidence"))

    def test_flat_images_have_no_matches(self):
        flat = self.write("flat.png", np.full((SIZE, SIZE), 120, np.uint8))

        code, out = run_script("exam-align-detect.py", flat, flat)

        self.assertEqual(code, 0)
        self.assertEqual(out, {"ok": False, "reason": "no_matches"})

    def test_unreadable_image_exits_zero_with_prefixed_reason(self):
        a = self.write("a.png", canvas(SIZE, SIZE, seed=1))
        broken = self.path("broken.png")
        with open(broken, "wb") as handle:
            handle.write(b"not an image")

        code, out = run_script("exam-align-detect.py", a, broken)

        self.assertEqual(code, 0)
        self.assertFalse(out["ok"])
        self.assertTrue(out["reason"].startswith("unreadable_image:"), out)

    def test_wrong_argument_count_is_a_usage_error(self):
        code, out = run_script("exam-align-detect.py", self.path("only-one.png"))

        self.assertEqual(code, 0)
        self.assertEqual(out, {"ok": False, "reason": "usage"})


if __name__ == "__main__":
    unittest.main()
