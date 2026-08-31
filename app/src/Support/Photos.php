<?php

declare(strict_types=1);

namespace Carl\Support;

use RuntimeException;

/**
 * Server-side photo handling (handoff Section 10).
 *
 * Validate with getimagesize, refuse a decompression bomb, read the date
 * taken BEFORE re-encoding, then re-decode and re-encode with GD -- which is
 * what strips any EXIF payload. The file lands under a random name in
 * var/photos/<user_id>/, outside public_html, and is only ever served through
 * a controller that checks ownership.
 */
final class Photos
{
    public function __construct(
        private string $storageRoot,
        private int $maxBytes = 2097152,
        private int $maxMegapixels = 40,
        private int $longEdge = 1920,
        private int $thumbEdge = 320,
        private int $quality = 85,
    ) {
    }

    /**
     * @param array<string,mixed> $file one entry from $_FILES
     * @return array{
     *   stored_name:string, thumb_name:string, width:int, height:int,
     *   bytes:int, taken_on:?string
     * }
     */
    public function store(array $file, int $userId): array
    {
        $this->assertUploadOk($file);

        $tmp = (string) $file['tmp_name'];
        if (!\is_uploaded_file($tmp) && !\is_file($tmp)) {
            throw new RuntimeException('That upload did not arrive.');
        }

        $info = @\getimagesize($tmp);
        if ($info === false) {
            throw new RuntimeException('That file is not an image Carl can read.');
        }

        [$width, $height] = $info;
        $mime = (string) ($info['mime'] ?? '');

        // A decompression bomb is small on disk and enormous decoded, and
        // memory_limit is 128M (hosting Section 4).
        $megapixels = ($width * $height) / 1_000_000;
        if ($megapixels > $this->maxMegapixels) {
            throw new RuntimeException(
                'That image is ' . \round($megapixels) . ' megapixels, which is too large to process. '
                . 'Take the photo at a lower resolution.'
            );
        }

        if (!\in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Photos must be JPEG, PNG or WebP.');
        }

        // Read the date taken before re-encoding drops it.
        $takenOn = $this->exifDate($tmp, $mime);

        $source = $this->decode($tmp, $mime);
        if ($source === false) {
            throw new RuntimeException('That image could not be decoded.');
        }

        try {
            $full = $this->scaleTo($source, $this->longEdge);
            $thumb = $this->scaleTo($source, $this->thumbEdge);

            $directory = $this->userDirectory($userId);
            $base = \bin2hex(\random_bytes(16));
            $storedName = $base . '.jpg';
            $thumbName = $base . '_t.jpg';

            $this->writeJpeg($full, $directory . '/' . $storedName, $this->quality);
            $this->writeJpeg($thumb, $directory . '/' . $thumbName, 80);

            $finalWidth = \imagesx($full);
            $finalHeight = \imagesy($full);
            $bytes = (int) \filesize($directory . '/' . $storedName);

            \imagedestroy($full);
            \imagedestroy($thumb);

            return [
                'stored_name' => $storedName,
                'thumb_name'  => $thumbName,
                'width'       => $finalWidth,
                'height'      => $finalHeight,
                'bytes'       => $bytes,
                'taken_on'    => $takenOn,
            ];
        } finally {
            \imagedestroy($source);
        }
    }

    /**
     * A stored photo, re-encoded small enough to sit in a PDF report
     * (handoff Section 13.2).
     *
     * The budget is what shapes this. GD holds a decoded image at roughly
     * width x height x 4 bytes, so a stored 1920px photo is about 11 MB while
     * it is open and memory_limit is 128M (hosting Section 4). Both handles
     * are freed before this returns, so a caller looping over twenty photos
     * pays for one at a time rather than for twenty.
     *
     * Returns null rather than throwing when a file is missing or unreadable:
     * a report with nineteen photos and a note is worth more than a 500.
     */
    public function downscaledJpeg(int $userId, string $storedName, int $longEdge, int $quality = 78): ?string
    {
        $path = $this->path($userId, $storedName);
        if (!\is_file($path)) {
            return null;
        }

        $info = @\getimagesize($path);
        if ($info === false) {
            return null;
        }
        // These files were written by store(), which already refused a
        // decompression bomb -- but the guard is cheap and this is the one
        // path that opens twenty of them in a row.
        if (($info[0] * $info[1]) / 1_000_000 > $this->maxMegapixels) {
            return null;
        }

        $source = $this->decode($path, (string) ($info['mime'] ?? ''));
        if ($source === false) {
            return null;
        }

        try {
            $scaled = $this->scaleTo($source, $longEdge);
        } finally {
            \imagedestroy($source);
        }

        try {
            \ob_start();
            $ok = \imagejpeg($scaled, null, $quality);
            $jpeg = (string) \ob_get_clean();
        } finally {
            \imagedestroy($scaled);
        }

        return $ok && $jpeg !== '' ? $jpeg : null;
    }

    /**
     * The same for an image that arrived in the request rather than from
     * disk -- the chart canvases a report POSTs up (handoff Section 13.2).
     *
     * Everything about this input is the browser's word: it is decoded here,
     * flattened onto white and re-encoded as a JPEG, which is what makes it
     * safe to hand to FPDF. FPDF's PNG path unpacks an alpha channel through
     * zlib into a full RGBA bitmap; a JPEG is embedded as-is. Going through
     * GD also means an SVG or a text file claiming to be a PNG dies here,
     * with a message, rather than somewhere inside a PDF writer.
     *
     * @param int $maxPixels decoded size cap; a canvas on a 3x phone is under
     *                       4 MP, so anything far above that is not a chart
     */
    public function chartJpeg(string $binary, int $maxPixels = 8_000_000, int $quality = 90): ?string
    {
        if ($binary === '') {
            return null;
        }

        $info = @\getimagesizefromstring($binary);
        if ($info === false || ($info[0] * $info[1]) > $maxPixels) {
            return null;
        }
        if (!\in_array((string) ($info['mime'] ?? ''), ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }

        $source = @\imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        try {
            $width = \imagesx($source);
            $height = \imagesy($source);
            $flat = \imagecreatetruecolor($width, $height);
            $this->flatten($flat, $width, $height);
            \imagecopy($flat, $source, 0, 0, 0, 0, $width, $height);
        } finally {
            \imagedestroy($source);
        }

        try {
            \ob_start();
            $ok = \imagejpeg($flat, null, $quality);
            $jpeg = (string) \ob_get_clean();
        } finally {
            \imagedestroy($flat);
        }

        return $ok && $jpeg !== '' ? $jpeg : null;
    }

    public function path(int $userId, string $storedName): string
    {
        // The name is generated here and stored; still, never let a value
        // from the database walk out of its directory.
        $safe = \basename($storedName);
        return $this->userDirectory($userId, false) . '/' . $safe;
    }

    public function delete(int $userId, string $storedName, ?string $thumbName): void
    {
        foreach ([$storedName, $thumbName] as $name) {
            if ($name === null || $name === '') {
                continue;
            }
            $path = $this->path($userId, $name);
            if (\is_file($path)) {
                @\unlink($path);
            }
        }
    }

    /** @param array<string,mixed> $file */
    private function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? \UPLOAD_ERR_NO_FILE);

        if ($error === \UPLOAD_ERR_INI_SIZE || $error === \UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException(
                'That photo is larger than the 2 MB this server accepts. The app normally shrinks '
                . 'photos before sending; if your browser could not, take a smaller one.'
            );
        }
        if ($error === \UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No photo was attached.');
        }
        if ($error !== \UPLOAD_ERR_OK) {
            throw new RuntimeException('The upload failed (code ' . $error . '). Try again.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('That photo arrived empty.');
        }
        if ($size > $this->maxBytes) {
            throw new RuntimeException('That photo is larger than 2 MB.');
        }
    }

    private function decode(string $path, string $mime): \GdImage|false
    {
        return match ($mime) {
            'image/jpeg' => @\imagecreatefromjpeg($path),
            'image/png'  => @\imagecreatefrompng($path),
            'image/webp' => @\imagecreatefromwebp($path),
            default      => false,
        };
    }

    private function scaleTo(\GdImage $source, int $longEdge): \GdImage
    {
        $width = \imagesx($source);
        $height = \imagesy($source);
        $longest = \max($width, $height);

        if ($longest <= $longEdge) {
            // Still re-encoded, because re-encoding is what strips EXIF.
            $copy = \imagecreatetruecolor($width, $height);
            $this->flatten($copy, $width, $height);
            \imagecopy($copy, $source, 0, 0, 0, 0, $width, $height);
            return $copy;
        }

        $scale = $longEdge / $longest;
        $newWidth = (int) \max(1, \round($width * $scale));
        $newHeight = (int) \max(1, \round($height * $scale));

        $resized = \imagecreatetruecolor($newWidth, $newHeight);
        $this->flatten($resized, $newWidth, $newHeight);
        \imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    /** Transparency becomes white rather than black when a PNG becomes JPEG. */
    private function flatten(\GdImage $image, int $width, int $height): void
    {
        $white = \imagecolorallocate($image, 255, 255, 255);
        if ($white !== false) {
            \imagefilledrectangle($image, 0, 0, $width, $height, $white);
        }
    }

    private function writeJpeg(\GdImage $image, string $path, int $quality): void
    {
        if (!\imagejpeg($image, $path, $quality)) {
            throw new RuntimeException('The photo could not be saved.');
        }
        // 0644 inside a 0700 directory: the directory is what keeps it private
        // (hosting Section 5.3).
        @\chmod($path, 0644);
    }

    private function userDirectory(int $userId, bool $create = true): string
    {
        $directory = $this->storageRoot . '/' . $userId;
        if ($create && !\is_dir($directory)) {
            if (!@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
                throw new RuntimeException('Photo storage is not writable.');
            }
        }
        return $directory;
    }

    /** @return string|null Y-m-d, if the file carried a date taken */
    private function exifDate(string $path, string $mime): ?string
    {
        if ($mime !== 'image/jpeg' || !\function_exists('exif_read_data')) {
            return null;
        }
        $exif = @\exif_read_data($path, 'EXIF', true);
        if (!\is_array($exif)) {
            return null;
        }
        foreach (['EXIF' => 'DateTimeOriginal', 'IFD0' => 'DateTime'] as $section => $key) {
            $raw = $exif[$section][$key] ?? null;
            if (!\is_string($raw)) {
                continue;
            }
            // EXIF writes "2026:08:30 14:05:11".
            if (\preg_match('/^(\d{4}):(\d{2}):(\d{2})/', $raw, $m) === 1) {
                return Clock::parseDate($m[1] . '-' . $m[2] . '-' . $m[3]);
            }
        }
        return null;
    }
}
