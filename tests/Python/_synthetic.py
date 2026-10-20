"""Synthetic textured images for the vision worker self-checks.

No patient data anywhere: random blobs and strokes on a flat background, blurred
and noised so ORB/Stitcher find features without being a trivial pattern.
"""
import json
import os
import subprocess
import sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SCRIPTS = os.path.join(REPO, "scripts")

try:
    import cv2
    import numpy as np

    HAS_CV2 = True
except ImportError:  # the system python of dev machines has no OpenCV
    cv2 = None
    np = None
    HAS_CV2 = False


def canvas(width: int, height: int, seed: int):
    rng = np.random.default_rng(seed)
    img = np.full((height, width), 90, np.uint8)

    for _ in range(int(width * height / 6000)):
        center = (int(rng.integers(0, width)), int(rng.integers(0, height)))
        cv2.circle(img, center, int(rng.integers(4, 40)), int(rng.integers(30, 220)), -1)

    for _ in range(int(width * height / 20000)):
        points = [(int(rng.integers(0, width)), int(rng.integers(0, height))) for _ in range(3)]
        cv2.polylines(img, [np.array(points)], False, int(rng.integers(20, 240)), int(rng.integers(1, 6)))

    img = cv2.GaussianBlur(img, (0, 0), 1.2)
    noisy = img.astype(np.float32) + rng.normal(0, 6, img.shape)

    return np.clip(noisy, 0, 255).astype(np.uint8)


def run_script(name: str, *args: str) -> tuple:
    """Runs a worker the way BoundedVisionProcess does (isolated mode, argv only)."""
    proc = subprocess.run(
        [sys.executable, "-I", os.path.join(SCRIPTS, name), *args],
        capture_output=True,
        text=True,
        timeout=60,
    )

    return proc.returncode, json.loads(proc.stdout) if proc.stdout.strip() else None
