<?php

declare(strict_types=1);

namespace Lib;

use RuntimeException;

/**
 * Photo handling for visit / attendance evidence, using the GD extension
 * (enabled by default on cPanel).
 *
 * Responsibilities
 *  - validate that an upload really is an image (never trust the extension)
 *  - downscale to a sane size so a 12 MP phone photo does not fill the quota
 *  - burn a watermark strip with agent name, date/time and lat/long into the
 *    pixels, so the evidence survives being copied out of the system
 *  - build a thumbnail for list views
 *  - strip EXIF (rotation is applied first) to avoid leaking extra metadata
 */
final class ImageProcessor
{
    private const MAX_EDGE = 1600;
    private const THUMB_EDGE = 320;
    private const JPEG_QUALITY = 82;

    /**
     * @return array{ok:bool,error:string,mime:string,width:int,height:int}
     */
    public static function inspect(string $path): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            return [
                'ok' => false,
                'error' => 'The PHP "gd" extension is not enabled, so photos cannot be processed. '
                    . 'Enable it in cPanel > Select PHP Version > Extensions.',
                'mime' => '', 'width' => 0, 'height' => 0,
            ];
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return [
                'ok' => false,
                'error' => 'The uploaded file is not a valid image.',
                'mime' => '', 'width' => 0, 'height' => 0,
            ];
        }

        $mime = (string) ($info['mime'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return [
                'ok' => false,
                'error' => 'Only JPG, PNG and WebP images are accepted (got ' . $mime . ').',
                'mime' => $mime, 'width' => 0, 'height' => 0,
            ];
        }

        return [
            'ok' => true, 'error' => '',
            'mime' => $mime,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }

    /**
     * Process one uploaded photo: resize, watermark, save as JPEG, make a thumb.
     *
     * @param array{name:string,date:string,latitude:float,longitude:float,accuracy:?float,extra:?string} $stamp
     * @return array{ok:bool,error:string,path:string,thumb:string,hash:string,bytes:int,width:int,height:int}
     */
    public static function processVisitPhoto(
        string $sourcePath,
        string $destinationAbsolute,
        string $thumbAbsolute,
        array $stamp
    ): array {
        $fail = static fn (string $msg): array => [
            'ok' => false, 'error' => $msg, 'path' => '', 'thumb' => '',
            'hash' => '', 'bytes' => 0, 'width' => 0, 'height' => 0,
        ];

        $check = self::inspect($sourcePath);
        if (!$check['ok']) {
            return $fail($check['error']);
        }

        $image = self::load($sourcePath, $check['mime']);
        if ($image === null) {
            return $fail('The image could not be decoded. It may be corrupted - please retake the photo.');
        }

        try {
            $image = self::applyExifRotation($image, $sourcePath, $check['mime']);
            $image = self::fit($image, self::MAX_EDGE);
            self::stamp($image, $stamp);

            foreach ([dirname($destinationAbsolute), dirname($thumbAbsolute)] as $dir) {
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    return $fail('Upload folder is not writable: ' . $dir
                        . '. Set permissions to 755 in cPanel File Manager.');
                }
            }

            if (!imagejpeg($image, $destinationAbsolute, self::JPEG_QUALITY)) {
                return $fail('The processed photo could not be saved. Check that uploads/ is writable (755).');
            }

            $thumb = self::fit($image, self::THUMB_EDGE, true);
            if (!imagejpeg($thumb, $thumbAbsolute, 70)) {
                // A missing thumbnail is not fatal - the full photo is saved.
                Logger::warning('Thumbnail could not be written: ' . $thumbAbsolute);
            }
            imagedestroy($thumb);

            $width = imagesx($image);
            $height = imagesy($image);

            return [
                'ok' => true,
                'error' => '',
                'path' => $destinationAbsolute,
                'thumb' => is_file($thumbAbsolute) ? $thumbAbsolute : '',
                'hash' => (string) hash_file('sha256', $destinationAbsolute),
                'bytes' => (int) filesize($destinationAbsolute),
                'width' => $width,
                'height' => $height,
            ];
        } catch (\Throwable $e) {
            Logger::error('Photo processing failed: ' . $e->getMessage());
            return $fail('Photo processing failed: ' . $e->getMessage());
        } finally {
            if (isset($image) && $image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    /**
     * Burn the evidence strip onto the bottom of the image.
     *
     * @param array{name:string,date:string,latitude:float,longitude:float,accuracy:?float,extra:?string} $stamp
     */
    public static function stamp(\GdImage $image, array $stamp): void
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $lines = [
            $stamp['name'],
            $stamp['date'],
            sprintf('Lat %.6f, Lng %.6f', $stamp['latitude'], $stamp['longitude'])
                . ($stamp['accuracy'] !== null ? sprintf('  (±%.0fm)', $stamp['accuracy']) : ''),
        ];
        if (!empty($stamp['extra'])) {
            $lines[] = (string) $stamp['extra'];
        }

        // GD's built-in font 3 is 7x13 px; scale the strip with the image so
        // the text stays readable on both small and large photos.
        $font = $width >= 900 ? 5 : 3;
        $lineHeight = imagefontheight($font) + 4;
        $padding = 8;
        $stripHeight = ($lineHeight * count($lines)) + ($padding * 2);
        $stripTop = max(0, $height - $stripHeight);

        // Semi-transparent dark background band.
        $band = imagecolorallocatealpha($image, 0, 0, 0, 55);
        if ($band !== false) {
            imagefilledrectangle($image, 0, $stripTop, $width, $height, $band);
        }

        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $accent = (int) imagecolorallocate($image, 120, 230, 190);
        $shadow = (int) imagecolorallocate($image, 0, 0, 0);

        $y = $stripTop + $padding;
        foreach ($lines as $index => $line) {
            $text = self::asciiSafe($line);
            // 1px shadow keeps the text legible over a bright photo.
            imagestring($image, $font, $padding + 1, $y + 1, $text, $shadow);
            imagestring($image, $font, $padding, $y, $text, $index === 0 ? $accent : $white);
            $y += $lineHeight;
        }

        // Tamper hint: a thin coloured rule at the very top of the strip.
        $rule = (int) imagecolorallocate($image, 13, 92, 70);
        imagefilledrectangle($image, 0, $stripTop, $width, $stripTop + 2, $rule);
    }

    /**
     * GD's bitmap fonts are Latin-1 only. Transliterate so a Devanagari or
     * accented name degrades to something readable instead of garbage.
     */
    private static function asciiSafe(string $text): string
    {
        if (preg_match('/[^\x20-\x7E]/', $text) !== 1) {
            return $text;
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                return (string) preg_replace('/[^\x20-\x7E]/', '', $converted);
            }
        }
        return (string) preg_replace('/[^\x20-\x7E]/', '', $text);
    }

    public static function load(string $path, string $mime): ?\GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };

        if ($image === false) {
            return null;
        }

        // Flatten PNG transparency onto white so the JPEG output looks right.
        if ($mime === 'image/png') {
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            if ($flat !== false) {
                $white = (int) imagecolorallocate($flat, 255, 255, 255);
                imagefilledrectangle($flat, 0, 0, imagesx($image), imagesy($image), $white);
                imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                imagedestroy($image);
                $image = $flat;
            }
        }

        return $image;
    }

    /** Rotate according to the EXIF orientation tag, then forget the EXIF. */
    private static function applyExifRotation(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);
        return $rotated;
    }

    /** Scale so the longest edge is at most $maxEdge. Never upscales. */
    public static function fit(\GdImage $image, int $maxEdge, bool $returnNew = false): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $maxEdge && !$returnNew) {
            return $image;
        }

        $ratio = $longest <= $maxEdge ? 1.0 : $maxEdge / $longest;
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if ($resized === false) {
            return $image;
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        if ($returnNew) {
            return $resized;
        }

        imagedestroy($image);
        return $resized;
    }

    /**
     * Decode a base64 data URI or raw base64 payload sent by the Android app
     * (used for the signature pad) and write it to disk as a PNG.
     *
     * @return array{ok:bool,error:string,bytes:int}
     */
    public static function saveBase64Png(string $payload, string $destinationAbsolute): array
    {
        if (preg_match('#^data:image/(png|jpeg);base64,#i', $payload, $m) === 1) {
            $payload = substr($payload, strlen($m[0]));
        }

        $binary = base64_decode(strtr($payload, ' ', '+'), true);
        if ($binary === false || $binary === '') {
            return ['ok' => false, 'error' => 'The signature image could not be decoded.', 'bytes' => 0];
        }
        if (strlen($binary) > 2 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'The signature image is too large (max 2 MB).', 'bytes' => 0];
        }

        $dir = dirname($destinationAbsolute);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Signature folder is not writable: ' . $dir, 'bytes' => 0];
        }

        // Verify it really is an image before trusting it.
        $tmp = tempnam(sys_get_temp_dir(), 'lrms_sig_');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'Could not create a temporary file for the signature.', 'bytes' => 0];
        }
        file_put_contents($tmp, $binary);
        $check = self::inspect($tmp);
        @unlink($tmp);

        if (!$check['ok']) {
            return ['ok' => false, 'error' => 'The signature payload is not a valid image.', 'bytes' => 0];
        }

        if (file_put_contents($destinationAbsolute, $binary) === false) {
            return ['ok' => false, 'error' => 'The signature could not be saved to disk.', 'bytes' => 0];
        }

        return ['ok' => true, 'error' => '', 'bytes' => strlen($binary)];
    }
}
