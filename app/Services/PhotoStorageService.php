<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use Lib\Crypto;
use Lib\ImageProcessor;
use Lib\Logger;

/**
 * Where uploaded evidence lives on disk, and how it gets watermarked.
 *
 * Layout under uploads/ (dated folders keep any single directory small enough
 * for cPanel File Manager to open):
 *
 *   uploads/visits/2026/07/29/<photo_uid>.jpg
 *   uploads/visits/2026/07/29/thumb_<photo_uid>.jpg
 *   uploads/visits/2026/07/29/sign_<visit_uid>.png
 *   uploads/attendance/2026/07/29/<uid>.jpg
 */
final class PhotoStorageService
{
    /**
     * Store one visit photo: validate, downscale, watermark, thumbnail.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @param array{agent:string,latitude:float,longitude:float,accuracy:?float,capturedAt:string,extra:?string} $stamp
     * @return array{ok:bool,error:string,photo_uid:string,relative:string,thumb:string,hash:string,bytes:int}
     */
    public function storeVisitPhoto(array $file, array $stamp): array
    {
        $fail = static fn (string $message): array => [
            'ok' => false, 'error' => $message, 'photo_uid' => '',
            'relative' => '', 'thumb' => '', 'hash' => '', 'bytes' => 0,
        ];

        $uploadError = $this->describeUploadError($file['error']);
        if ($uploadError !== null) {
            return $fail($uploadError);
        }

        $maxBytes = (int) Config::get('max_photo_bytes', 8 * 1024 * 1024);
        if ($file['size'] > $maxBytes) {
            return $fail(sprintf(
                'Photo "%s" is %.1f MB which exceeds the %.0f MB limit.',
                $this->safeName($file['name']),
                $file['size'] / 1048576,
                $maxBytes / 1048576
            ));
        }

        if (!is_uploaded_file($file['tmp_name']) && !is_file($file['tmp_name'])) {
            return $fail('The photo upload did not arrive completely. Please retry.');
        }

        $photoUid = Crypto::uuid4();
        $datePath = 'visits/' . date('Y/m/d', strtotime($stamp['capturedAt']) ?: time());
        $relative = $datePath . '/' . $photoUid . '.jpg';
        $thumbRelative = $datePath . '/thumb_' . $photoUid . '.jpg';

        $result = ImageProcessor::processVisitPhoto(
            $file['tmp_name'],
            UPLOAD_PATH . '/' . $relative,
            UPLOAD_PATH . '/' . $thumbRelative,
            [
                'name'      => $stamp['agent'],
                'date'      => date('d-m-Y h:i A', strtotime($stamp['capturedAt']) ?: time()),
                'latitude'  => $stamp['latitude'],
                'longitude' => $stamp['longitude'],
                'accuracy'  => $stamp['accuracy'],
                'extra'     => $stamp['extra'],
            ]
        );

        if (!$result['ok']) {
            return $fail($result['error']);
        }

        return [
            'ok' => true,
            'error' => '',
            'photo_uid' => $photoUid,
            'relative' => $relative,
            'thumb' => is_file(UPLOAD_PATH . '/' . $thumbRelative) ? $thumbRelative : '',
            'hash' => $result['hash'],
            'bytes' => $result['bytes'],
        ];
    }

    /**
     * Store an attendance selfie (same watermark treatment).
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{ok:bool,error:string,relative:string}
     */
    public function storeAttendancePhoto(array $file, string $agentName, float $lat, float $lng, ?float $accuracy, string $label): array
    {
        $uploadError = $this->describeUploadError($file['error']);
        if ($uploadError !== null) {
            return ['ok' => false, 'error' => $uploadError, 'relative' => ''];
        }

        $uid = Crypto::uuid4();
        $relative = 'attendance/' . date('Y/m/d') . '/' . $uid . '.jpg';
        $thumb = 'attendance/' . date('Y/m/d') . '/thumb_' . $uid . '.jpg';

        $result = ImageProcessor::processVisitPhoto(
            $file['tmp_name'],
            UPLOAD_PATH . '/' . $relative,
            UPLOAD_PATH . '/' . $thumb,
            [
                'name'      => $agentName . ' - ' . $label,
                'date'      => date('d-m-Y h:i A'),
                'latitude'  => $lat,
                'longitude' => $lng,
                'accuracy'  => $accuracy,
                'extra'     => null,
            ]
        );

        return $result['ok']
            ? ['ok' => true, 'error' => '', 'relative' => $relative]
            : ['ok' => false, 'error' => $result['error'], 'relative' => ''];
    }

    /**
     * Store a base64 signature PNG produced by the app's signature pad.
     *
     * @return array{ok:bool,error:string,relative:string}
     */
    public function storeSignature(string $base64, string $visitUid): array
    {
        $relative = 'visits/' . date('Y/m/d') . '/sign_' . preg_replace('/[^a-f0-9\-]/i', '', $visitUid) . '.png';
        $result = ImageProcessor::saveBase64Png($base64, UPLOAD_PATH . '/' . $relative);

        return $result['ok']
            ? ['ok' => true, 'error' => '', 'relative' => $relative]
            : ['ok' => false, 'error' => $result['error'], 'relative' => ''];
    }

    /** Public URL for a stored file. */
    public static function url(?string $relative): ?string
    {
        if ($relative === null || $relative === '') {
            return null;
        }
        return Config::baseUrl() . 'uploads/' . ltrim($relative, '/');
    }

    public static function absolute(?string $relative): ?string
    {
        if ($relative === null || $relative === '') {
            return null;
        }
        $path = UPLOAD_PATH . '/' . ltrim($relative, '/');
        return is_file($path) ? $path : null;
    }

    /** Turn PHP's numeric upload error into something a field agent can act on. */
    private function describeUploadError(int $code): ?string
    {
        switch ($code) {
            case UPLOAD_ERR_OK:
                return null;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The photo is larger than the server allows. '
                    . 'Ask your administrator to raise upload_max_filesize (currently '
                    . ini_get('upload_max_filesize') . ').';
            case UPLOAD_ERR_PARTIAL:
                return 'The photo upload was interrupted. Please retry on a better connection.';
            case UPLOAD_ERR_NO_FILE:
                return 'No photo was received.';
            case UPLOAD_ERR_NO_TMP_DIR:
                Logger::error('PHP upload_tmp_dir is missing on this host.');
                return 'The server has no temporary upload folder configured. Please contact your administrator.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'The server could not write the photo to disk. Please contact your administrator.';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension blocked the upload. Please contact your administrator.';
            default:
                return 'The photo could not be uploaded (error code ' . $code . ').';
        }
    }

    private function safeName(string $name): string
    {
        return substr((string) preg_replace('/[^\w\.\- ]/', '', $name), 0, 60);
    }
}
