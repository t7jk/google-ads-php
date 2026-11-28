<?php
declare(strict_types=1);

namespace App\Media;

/** Format and dimensions of a JPG/PNG image by its CONTENT (getimagesizefromstring), not by file name or Content-Type header. */
final class ImageInfo
{
    public const ERR_FORMAT = 'Only JPG and PNG files are supported';
    public const ERR_BROKEN = 'Corrupted image file';

    /** @return array{format:string, width:int, height:int, ext:string, mime:string}|array{error:string} */
    public static function read(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            // Starts like a JPG/PNG but cannot be read = corrupted; anything else = wrong format.
            $looksImage = str_starts_with($bytes, "\xFF\xD8") || str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
            return ['error' => $looksImage ? self::ERR_BROKEN : self::ERR_FORMAT];
        }
        [$w, $h, $type] = $info;
        if ($type !== IMAGETYPE_JPEG && $type !== IMAGETYPE_PNG) {
            return ['error' => self::ERR_FORMAT];
        }
        if ($w < 1 || $h < 1) {
            return ['error' => self::ERR_BROKEN];
        }
        $png = $type === IMAGETYPE_PNG;
        return ['format' => $png ? 'png' : 'jpeg', 'width' => $w, 'height' => $h, 'ext' => $png ? 'png' : 'jpg', 'mime' => $png ? 'image/png' : 'image/jpeg'];
    }
}
