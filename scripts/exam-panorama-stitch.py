#!/usr/bin/env python3
"""Panorama stitching worker — Panorama (benchmark Ger Exames/iWayBrasil,
aba "Montage"). Invoked only by App\\Services\\EyeImages\\BoundedVisionProcess
(never directly from request input) with fixed, caller-assembled argv.

Usage: exam-panorama-stitch.py <out_path.png> <image1> <image2> [imageN...]

Prints a single JSON line to stdout ({"ok": true} or {"ok": false, "reason":
str}); the PNG bytes themselves are written to <out_path>, never to stdout
(binary-safe — avoids corrupting output through a text-bounded pipe).
Exit code is 0 for every outcome the script handles, failures included (the
reason travels in the JSON, so the PHP side can map it to a message). A
non-zero exit is reserved for a real crash (rlimit, segfault, missing
interpreter), which the caller reports as a generic worker failure.
"""
import json
import sys


def fail(reason: str) -> None:
    print(json.dumps({"ok": False, "reason": reason}))
    sys.exit(0)


def main() -> None:
    if len(sys.argv) < 4:
        fail("usage")
        return

    out_path = sys.argv[1]
    image_paths = sys.argv[2:]

    try:
        import cv2
    except ImportError:
        fail("cv2_unavailable")
        return

    # Server worker: no OpenCL runtime/driver (it reserves a large address
    # range the caller's rlimit does not allow, and a GPU is not expected).
    cv2.ocl.setUseOpenCL(False)

    images = []
    for path in image_paths:
        img = cv2.imread(path, cv2.IMREAD_COLOR)
        if img is None:
            fail(f"unreadable_image:{path}")
            return
        images.append(img)

    stitcher = cv2.Stitcher_create(cv2.Stitcher_PANORAMA)
    status, result = stitcher.stitch(images)

    if status != cv2.Stitcher_OK:
        # Códigos do OpenCV: ERR_NEED_MORE_IMGS=1, ERR_HOMOGRAPHY_EST_FAIL=2,
        # ERR_CAMERA_PARAMS_ADJUST_FAIL=3 — imagens sem overlap/feature
        # suficiente pro algoritmo encontrar correspondência (comum em
        # retinografia de baixo contraste), não é bug do script.
        fail(f"stitch_failed:{int(status)}")
        return

    # Encode in memory instead of cv2.imwrite(): the caller's output path is a
    # tempnam() without extension, from which imwrite cannot pick the encoder.
    try:
        encoded, buffer = cv2.imencode(".png", result)
        if not encoded:
            fail("write_failed")
            return
        with open(out_path, "wb") as handle:
            handle.write(buffer.tobytes())
    except (cv2.error, OSError):
        fail("write_failed")
        return

    print(json.dumps({"ok": True}))
    sys.exit(0)


if __name__ == "__main__":
    main()
