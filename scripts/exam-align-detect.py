#!/usr/bin/env python3
"""Auto-align worker — Auto-alinhar (benchmark Ger Exames/iWayBrasil, aba
"Align"). Invoked only by App\\Services\\EyeImages\\BoundedVisionProcess (never
directly from request input) with fixed, caller-assembled argv.

Usage: exam-align-detect.py <image_a> <image_b>

Finds the translation that lays image B over image A (A is the base, B is the
overlay). B is resized to A's dimensions first — the Compare screen stretches
both images to the same width, so dx/dy are in pixels of A's natural size and
convert to screen pixels with a single scale factor.

Prints a single JSON line to stdout:
  {"ok": true, "dx": float, "dy": float, "confidence": float}
  {"ok": false, "reason": str}
Exit code is 0 for every outcome the script handles, failures included (the
reason travels in the JSON, so the PHP side can map it to a message). A
non-zero exit is reserved for a real crash (rlimit, segfault, missing
interpreter), which the caller reports as a generic worker failure.

Phase 1 uses translation only: a similarity transform is estimated (ORB +
RANSAC) and reduced to the displacement of the image center, so a small
rotation/scale difference between the exams does not skew the offset.
"""
import json
import sys

ORB_FEATURES = 2000
RATIO_TEST = 0.75
MIN_MATCHES = 12
MIN_INLIERS = 10
MIN_CONFIDENCE = 0.3
RANSAC_REPROJ = 3.0


def emit(payload: dict) -> None:
    print(json.dumps(payload))
    sys.exit(0)


def fail(reason: str) -> None:
    emit({"ok": False, "reason": reason})


def main() -> None:
    if len(sys.argv) != 3:
        fail("usage")
        return

    try:
        import cv2
        import numpy as np
    except ImportError:
        fail("cv2_unavailable")
        return

    path_a, path_b = sys.argv[1], sys.argv[2]

    img_a = cv2.imread(path_a, cv2.IMREAD_GRAYSCALE)
    if img_a is None:
        fail(f"unreadable_image:{path_a}")
        return

    img_b = cv2.imread(path_b, cv2.IMREAD_GRAYSCALE)
    if img_b is None:
        fail(f"unreadable_image:{path_b}")
        return

    height, width = img_a.shape[:2]
    if img_b.shape[:2] != (height, width):
        img_b = cv2.resize(img_b, (width, height), interpolation=cv2.INTER_AREA)

    orb = cv2.ORB_create(nfeatures=ORB_FEATURES)
    kp_a, desc_a = orb.detectAndCompute(img_a, None)
    kp_b, desc_b = orb.detectAndCompute(img_b, None)

    if desc_a is None or desc_b is None or len(kp_a) < 2 or len(kp_b) < 2:
        fail("no_matches")
        return

    matcher = cv2.BFMatcher(cv2.NORM_HAMMING)
    good = []
    for pair in matcher.knnMatch(desc_b, desc_a, k=2):
        if len(pair) == 2 and pair[0].distance < RATIO_TEST * pair[1].distance:
            good.append(pair[0])

    if len(good) < MIN_MATCHES:
        fail("no_matches")
        return

    src = np.float32([kp_b[m.queryIdx].pt for m in good]).reshape(-1, 1, 2)
    dst = np.float32([kp_a[m.trainIdx].pt for m in good]).reshape(-1, 1, 2)

    matrix, inliers = cv2.estimateAffinePartial2D(
        src, dst, method=cv2.RANSAC, ransacReprojThreshold=RANSAC_REPROJ
    )

    if matrix is None or inliers is None:
        fail("low_confidence")
        return

    inlier_count = int(inliers.sum())
    confidence = inlier_count / len(good)

    if inlier_count < MIN_INLIERS or confidence < MIN_CONFIDENCE:
        fail("low_confidence")
        return

    # Displacement of the image center under B->A. For a pure translation this
    # is the matrix translation; with a little rotation/scale it stays centered.
    center = np.array([width / 2.0, height / 2.0, 1.0])
    moved = matrix @ center

    emit({
        "ok": True,
        "dx": round(float(moved[0] - width / 2.0), 3),
        "dy": round(float(moved[1] - height / 2.0), 3),
        "confidence": round(confidence, 4),
    })


if __name__ == "__main__":
    main()
