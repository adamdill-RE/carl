# QR encoder fixtures

`matrices.json` is what `tests/cases/21_tags_test.php` asserts the encoder
reproduces, module for module. Eleven payloads, each one a row of `#` and `.`
per module, with no quiet zone — the renderers add that.

## Why the fixtures are the test

`Carl\Qr\Encoder` is ~700 lines of ISO 18004 written from the standard rather
than vendored, because no QR-image web service may sit on a request path and
this project has no Composer (`docs/QR-TAGS-SPEC.md` §4). A QR symbol does not
degrade: one wrong module is a tag that does not scan, on a hundred stakes that
have already been printed, applied and put in the ground. So the encoder is
pinned against output that was verified once, offline, by things that share no
code with it.

## Regenerating them

```
pip install segno opencv-python-headless numpy
python3 tests/fixtures/qr/generate.py
```

Nothing in the suite runs this — it needs a network install and two Python
libraries, and the host has neither (hosting §3). It is checked in so the
fixtures can be rebuilt, and so that what they are worth is written down rather
than remembered.

`generate.py` refuses to write anything unless, for every payload:

1. **A decoder reads the exact payload back out of the matrix.** This is the
   check that actually says "this tag will scan", and it is independent in the
   strongest sense: a decoder shares no table and no code path with an encoder.
2. **An independent encoder (segno) produces the same matrix**, module for
   module — including the same version, the same mode and the same mask.
3. **Or differs only in the one place it is allowed to.** segno's
   `write_padding_bits()` adds `8 - (length % 8)` zero bits, which is eight
   bits — a whole extra `0x00` codeword — when the stream already ends on a
   codeword boundary. ISO 18004 §7.4.10 adds padding bits only "if the bit
   stream length is such that it does not end at a codeword boundary", so on
   those payloads segno emits a pad codeword the standard does not call for.
   It is harmless (a decoder stops at the character count and never reads it)
   and it changes a data codeword, which changes every error-correction
   codeword in its block, which can change which mask scores lowest — so on
   those payloads the two symbols legitimately differ and only check 1 says
   anything. The script recomputes where the stream ends and tolerates a
   mismatch on exactly those payloads, and nowhere else.

At the last run: 11 payloads, all 11 decoded back to their exact payload, 7
module-for-module identical to segno, 4 on a codeword boundary.

## What is deliberately NOT tolerated

A different chosen **mask**, on any payload that is not on a codeword boundary.
Mask choice is a readability optimisation and never a correctness one — all
eight decode — but it is the one thing where two defensible readings of §7.8
part company, and Carl matches segno on purpose. See
`Carl\Qr\Encoder::chooseMask`, which is the docblock about it.
