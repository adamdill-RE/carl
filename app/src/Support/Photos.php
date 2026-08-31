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
