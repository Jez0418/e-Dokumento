<?php
declare(strict_types=1);

/**
 * Upload checks: size, and file type by signature (magic bytes), not by the
 * browser-supplied MIME type or extension.
 */
final class Upload
{
    public const MAX_BYTES = 2097152; // 2 MB
    public const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /**
     * @return array{tmp:string,name:string,mime:string,ext:string,size:int}|string|null
     *         file info, an error message, or null when nothing was uploaded
     */
    public static function check(?array $file, string $label, bool $required): array|string|null
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($file === null || $error === UPLOAD_ERR_NO_FILE) {
            return $required ? "{$label} is required." : null;
        }
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return "{$label} must be 2 MB or smaller.";
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return "{$label} could not be uploaded. Try again.";
        }
        $size = (int) $file['size'];
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return "{$label} must be 2 MB or smaller.";
        }
        $mime = self::sniff((string) $file['tmp_name']);
        if ($mime === null) {
            return "{$label} must be a PDF, JPG or PNG file.";
        }
        $name = preg_replace('/[^\p{L}\p{N}._\- ]/u', '_', basename((string) $file['name'])) ?: 'file';
        return ['tmp' => (string) $file['tmp_name'], 'name' => mb_substr($name, 0, 200), 'mime' => $mime, 'ext' => self::TYPES[$mime], 'size' => $size];
    }

    private static function sniff(string $path): ?string
    {
        $head = @file_get_contents($path, false, null, 0, 8);
        if (!is_string($head) || $head === '') {
            return null;
        }
        if (str_starts_with($head, '%PDF-')) {
            return 'application/pdf';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if ($head === "\x89PNG\r\n\x1A\n") {
            return 'image/png';
        }
        return null;
    }

    /** Uploads to a private bucket under the resident's folder; returns the storage path. */
    public static function store(Supabase $db, string $bucket, string $residentId, array $file): string
    {
        $path = $residentId . '/' . bin2hex(random_bytes(12)) . '.' . $file['ext'];
        $bytes = file_get_contents($file['tmp']);
        if ($bytes === false) {
            throw new RuntimeException('Could not read the uploaded file.');
        }
        $db->upload($bucket, $path, $bytes, $file['mime']);
        return $path;
    }

    /** Normalises PHP's $_FILES['field'][...][key] layout into [key => file]. */
    public static function group(string $field): array
    {
        $out = [];
        $f = $_FILES[$field] ?? null;
        if (!is_array($f) || !is_array($f['name'] ?? null)) {
            return $out;
        }
        foreach ($f['name'] as $key => $name) {
            $out[(string) $key] = [
                'name'     => $name,
                'type'     => $f['type'][$key] ?? '',
                'tmp_name' => $f['tmp_name'][$key] ?? '',
                'error'    => $f['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $f['size'][$key] ?? 0,
            ];
        }
        return $out;
    }
}

/** Link that opens a private file through a short-lived signed URL. */
function file_link(string $bucket, string $path): string
{
    return url('files', ['bucket' => $bucket, 'path' => $path]);
}
