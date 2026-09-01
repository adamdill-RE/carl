"""Regenerate tests/fixtures/qr/matrices.json.

    pip install segno opencv-python-headless numpy
    python3 tests/fixtures/qr/generate.py

Nothing in the test suite runs this: it needs a network install and two
Python libraries, and the host has neither (hosting Section 3). It is checked
in so that the fixtures can be rebuilt, and so that what they are worth is
written down rather than remembered.

WHAT A FIXTURE IS WORTH HERE

docs/QR-TAGS-SPEC.md Section 4.1 asks for matrices captured from an
independent implementation and asserted bit for bit, on the grounds that "a
PHP decoder does not exist to round-trip against". That is true of PHP and
not of this script, so it does both, and the fixture is only written when
every check passes:

  1. ROUND TRIP. Each matrix is rendered with its quiet zone and read back by
     OpenCV's QR decoder. The decoded text must equal the payload exactly.
     This is the check that actually says "this tag will scan", and it is
     independent of Carl in the strongest sense: a decoder shares no code and
     no table with an encoder.

  2. INDEPENDENT ENCODER. segno encodes the same payload and the two matrices
     are compared module by module.

  3. THE ONE PERMITTED DIFFERENCE. segno's write_padding_bits() adds
     `8 - (length % 8)` zero bits, which is eight bits -- a whole extra 0x00
     codeword -- when the stream already ends on a codeword boundary. ISO
     18004 Section 7.4.10 adds padding bits only "if the bit stream length is
     such that it does not end at a codeword boundary", so on those payloads
     segno emits one pad codeword the standard does not call for. It is
     harmless (a decoder stops at the character count and never reads it) and
     it is a real difference, so this script does not paper over it: it
     recomputes where the stream ends and only tolerates a mismatch on the
     payloads where that boundary is hit. Everything else must agree exactly.

Both libraries choose the mask by their own reading of Section 7.8 -- see
Carl\\Qr\\Encoder::chooseMask -- so a difference in the chosen mask is a real
difference and is NOT tolerated. Carl matches segno there deliberately.
"""

import json
import pathlib
import subprocess
import sys

import numpy as np
import cv2
import segno

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parents[2]

# name -> (payload, error correction level)
#
# Section 4.1 asks for at least: the shortest and the longest payload that fit
# version 3 at level Q, one that forces a version bump, and one that forces
# the byte-mode fallback. All four are here, and so is the real tag URL.
CASES = {
    # The tag as printed (Section 2.1): 44 characters, version 3, level Q,
    # three characters of headroom. Everything physical is sized around this.
    "tag_url":       ("HTTPS://WWW.RESHIFTMANAGER.COM/CARL/T/AB7K4M", "Q"),
    # 30 characters: one more than version 2 holds, so the shortest payload
    # that needs version 3.
    "v3q_shortest":  ("HTTPS://CARL.GARDEN/T/AB7K4MXY", "Q"),
    # 47 characters: exactly what version 3 at Q holds, and the arithmetic in
    # Section 2.3 is wrong if this does not fit.
    "v3q_longest":   ("HTTPS://WWW.RESHIFTMANAGER.COM/CARL/T/ABCDEFGHJ", "Q"),
    # 48 characters: one more, so the version bumps to 4.
    "version_bump":  ("HTTPS://WWW.RESHIFTMANAGER.COM/CARL/T/ABCDEFGHJK", "Q"),
    # The same URL in lower case. Section 2.2 is the whole argument for
    # uppercase: this is byte mode, and it costs a version.
    "byte_fallback": ("https://www.reshiftmanager.com/carl/t/ab7k4m", "Q"),
    # Section 2.3 lever 1, the short domain: 28 characters, version 2.
    "short_domain":  ("HTTPS://CARL.GARDEN/T/AB7K4M", "Q"),
    # The same tag URL one level down, which is lever 3 and the one not to
    # take.
    "level_m":       ("HTTPS://WWW.RESHIFTMANAGER.COM/CARL/T/AB7K4M", "M"),
    # 16 characters: exactly version 1 at Q.
    "v1q_full":      ("HTTPS://A.CO/AB7K", "Q"),
    # The smallest thing there is.
    "one_char":      ("A", "M"),
    # Byte mode in the smallest symbol, and byte mode in the largest one this
    # encoder builds.
    "byte_v1m":      ("hello world", "M"),
    "byte_v4m":      ("https://www.reshiftmanager.com/carl/t/ab7k4m/xyz-0123456789", "M"),
}

ALNUM = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:"


def data_bits(payload, mode):
    """The length of the message bit stream before terminator and padding."""
    if mode == "byte":
        return 4 + 8 + len(payload) * 8
    pairs, odd = divmod(len(payload), 2)
    return 4 + 9 + pairs * 11 + (6 if odd else 0)


CAPACITY_BITS = {  # data codewords x 8, per version and level
    (1, "M"): 128, (1, "Q"): 104, (2, "M"): 224, (2, "Q"): 176,
    (3, "M"): 352, (3, "Q"): 272, (4, "M"): 512, (4, "Q"): 384,
}


def lands_on_codeword_boundary(payload, mode, capacity_bits):
    """Does the stream end flush on a byte, once the terminator is on it?

    That is exactly when segno appends the extra 0x00 codeword described in
    the module docstring.
    """
    used = data_bits(payload, mode)
    return (used + min(4, capacity_bits - used)) % 8 == 0


def carl_matrices():
    """Ask Carl's own encoder for every case, in one PHP process."""
    script = r"""<?php
declare(strict_types=1);
foreach (['BitWriter','Galois','Penalty','Symbol','Encoder'] as $f) {
    require __DIR__ . '/../../../app/src/Qr/' . $f . '.php';
}
$cases = json_decode((string) \file_get_contents($argv[1]), true);
$out = [];
foreach ($cases as $name => $spec) {
    $s = \Carl\Qr\Encoder::encode($spec[0], $spec[1]);
    $out[$name] = [
        'payload' => $spec[0], 'ec' => $spec[1], 'version' => $s->version,
        'mode' => $s->mode, 'mask' => $s->mask,
        'data_codewords' => null, 'rows' => $s->toRows(),
    ];
}
echo \json_encode($out);
"""
    runner = HERE / "_carl_dump.php"
    cases = HERE / "_cases.json"
    runner.write_text(script)
    cases.write_text(json.dumps(CASES))
    try:
        raw = subprocess.run(
            ["php", str(runner), str(cases)],
            check=True, capture_output=True, text=True,
        ).stdout
    finally:
        runner.unlink(missing_ok=True)
        cases.unlink(missing_ok=True)
    return json.loads(raw)


def decodes_to(rows, payload):
    """Render with the four-module quiet zone and read it back."""
    n = len(rows)
    quiet, scale = 4, 8
    img = np.full((n + 2 * quiet, n + 2 * quiet), 255, dtype=np.uint8)
    for r, line in enumerate(rows):
        for c, ch in enumerate(line):
            if ch == "#":
                img[r + quiet, c + quiet] = 0
    big = np.kron(img, np.ones((scale, scale), dtype=np.uint8))
    text, _, _ = cv2.QRCodeDetector().detectAndDecode(big)
    return text == payload, text


def main():
    carl = carl_matrices()
    failures = []
    tolerated = []

    for name, (payload, ec) in CASES.items():
        got = carl[name]

        ok, text = decodes_to(got["rows"], payload)
        if not ok:
            failures.append(f"{name}: decoder read {text!r}, not the payload")
            continue

        ref = segno.make(payload, error=ec, micro=False, boost_error=False)
        ref_rows = ["".join("#" if m else "." for m in row) for row in ref.matrix]

        capacity_bits = CAPACITY_BITS[(got["version"], ec)]
        boundary = lands_on_codeword_boundary(
            payload, got["mode"], capacity_bits
        )

        # Version and mode are arithmetic, not judgement: they must agree
        # whatever the padding does.
        if ref.version != got["version"] or ref.mode != got["mode"]:
            failures.append(
                f"{name}: segno says version {ref.version} mode {ref.mode}, "
                f"Carl says version {got['version']} mode {got['mode']}"
            )
            continue

        if boundary:
            # segno's extra pad codeword changes a data codeword, which
            # changes every error correction codeword in its block, which can
            # change which mask scores lowest. So on these payloads the two
            # symbols legitimately differ and only the round-trip above says
            # anything -- which is the check that matters. Recorded rather
            # than hidden.
            tolerated.append(name)
            continue

        if ref.mask != got["mask"]:
            failures.append(
                f"{name}: segno chose mask {ref.mask}, Carl chose {got['mask']}"
            )
        elif ref_rows != got["rows"]:
            differing = sum(
                1 for a, b in zip("".join(ref_rows), "".join(got["rows"])) if a != b
            )
            failures.append(f"{name}: {differing} modules differ from segno")

    if failures:
        print("FAILED:")
        for line in failures:
            print("  " + line)
        return 1

    out = {
        name: {
            "payload": carl[name]["payload"], "ec": carl[name]["ec"],
            "version": carl[name]["version"], "mode": carl[name]["mode"],
            "mask": carl[name]["mask"], "rows": carl[name]["rows"],
        }
        for name in CASES
    }
    (HERE / "matrices.json").write_text(json.dumps(out, indent=1) + "\n")

    print(f"{len(CASES)} cases: every one decoded back to its exact payload.")
    print(f"{len(CASES) - len(tolerated)} are module-for-module identical to segno.")
    print(f"{len(tolerated)} land on a codeword boundary, where segno emits its "
          f"extra pad codeword and the symbols legitimately differ: "
          f"{', '.join(tolerated) or 'none'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
