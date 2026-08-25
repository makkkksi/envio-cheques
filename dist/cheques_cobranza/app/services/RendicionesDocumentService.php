<?php
/**
 * Validación y almacenamiento seguro de fotografías de rendiciones.
 */

require_once __DIR__ . '/../config/app.php';

class RendicionesDocumentService
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heic',
    ];

    public static function store(array $file, int $empresaId, int $vendedorId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Debe adjuntar una fotografía válida del documento.');
        }
        if (!isset($file['tmp_name'], $file['size']) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('La carga del archivo no es válida.');
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > RENDICIONES_MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('La fotografía supera el máximo permitido de 10 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('El tipo real del archivo no está permitido.');
        }

        $month = date('Y-m');
        $relativeDirectory = "uploads/{$empresaId}/{$month}/rendiciones/{$vendedorId}";
        $absoluteDirectory = rtrim(UPLOADS_BASE_PATH, '/\\')
            . DIRECTORY_SEPARATOR . $empresaId
            . DIRECTORY_SEPARATOR . $month
            . DIRECTORY_SEPARATOR . 'rendiciones'
            . DIRECTORY_SEPARATOR . $vendedorId;

        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento del documento.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::MIME_EXTENSIONS[$mime];
        $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new RuntimeException('No fue posible almacenar la fotografía del documento.');
        }

        return [
            'relative_path' => $relativeDirectory . '/' . $filename,
            'absolute_path' => $absolutePath,
        ];
    }

    public static function rollback(?string $absolutePath): void
    {
        if ($absolutePath && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}
