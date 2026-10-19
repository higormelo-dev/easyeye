#!/usr/bin/env python3
"""Panorama stitching worker — Panorama (benchmark Ger Exames/iWayBrasil,
aba "Montage"). Invoked only by App\\Services\\EyeImages\\BoundedPanoramaProcess
(never directly from request input) with fixed, caller-assembled argv.

Usage: exam-panorama-stitch.py <out_path.png> <image1> <image2> [imageN...]

Prints a single JSON line to stdout ({"ok": true} or {"ok": false, "reason":
str}); the PNG bytes themselves are written to <out_path>, never to stdout
(binary-safe — avoids corrupting output through a text-bounded pipe).
Exit code 0 on success, 1 on any failure (including partial stitch).
"""
import json
import sys


def fail(reason: str) -> None:
    print(json.dumps({"ok": False, "reason": reason}))
    sys.exit(1)


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

    ok = cv2.imwrite(out_path, result)
    if not ok:
        fail("write_failed")
        return

    print(json.dumps({"ok": True}))
    sys.exit(0)


if __name__ == "__main__":
    main()
