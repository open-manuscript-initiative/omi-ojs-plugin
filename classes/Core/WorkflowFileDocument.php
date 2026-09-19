<?php
namespace APP\plugins\generic\studioIntegration\classes\Core;

final class WorkflowFileDocument
{
    public const PROTOCOL = 'omi-workflow-file/1';
    public const MAX_BYTES = 64 * 1024 * 1024;

    /**
     * Validate and decode a Studio -> OJS workflow file payload.
     *
     * OJS determines the persisted MIME type from the stored bytes. The
     * client-declared media type is retained only as transfer metadata.
     *
     * @return array{
     *   fileName:string,
     *   mediaType:string,
     *   bytes:string,
     *   byteLength:int,
     *   sha256:string,
     *   extension:string
     * }
     */
    public static function decode(
        string $fileName,
        string $mediaType,
        string $contentBase64
    ): array {
        $fileName = trim($fileName);
        $mediaType = trim($mediaType);

        if (
            $fileName === '' ||
            strlen($fileName) > 255 ||
            $fileName === '.' ||
            $fileName === '..' ||
            str_contains($fileName, "\0") ||
            str_contains($fileName, '/') ||
            str_contains($fileName, '\\') ||
            preg_match('/[\x00-\x1F\x7F]/', $fileName)
        ) {
            throw new \InvalidArgumentException('The workflow file name is invalid.');
        }

        if (
            $mediaType === '' ||
            strlen($mediaType) > 128 ||
            !preg_match(
                '~^[a-z0-9][a-z0-9!#            !preg_match('#^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*(?:\s*;[^\r\n]*)?$#i', $mediaType)
^_.+*\x27-]*/[a-z0-9][a-z0-9!#            !preg_match('#^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*(?:\s*;[^\r\n]*)?$#i', $mediaType)
^_.+*\x27-]*(?:\\s*;[^\\r\\n]*)?$~i',
                $mediaType
            )
        ) {
            throw new \InvalidArgumentException('The workflow file media type is invalid.');
        }

        if ($contentBase64 === '') {
            throw new \InvalidArgumentException('The workflow file is empty.');
        }

        $maxEncodedLength = (int)(ceil(self::MAX_BYTES / 3) * 4) + 8;
        if (strlen($contentBase64) > $maxEncodedLength) {
            throw new \InvalidArgumentException('The workflow file exceeds the maximum transfer size.');
        }

        $bytes = base64_decode($contentBase64, true);
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException('The workflow file content is not valid Base64.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The workflow file exceeds the maximum transfer size.');
        }

        $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== '' && !preg_match('/^[a-z0-9]{1,16}$/', $extension)) {
            $extension = '';
        }

        return [
            'fileName' => $fileName,
            'mediaType' => $mediaType,
            'bytes' => $bytes,
            'byteLength' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'extension' => $extension,
        ];
    }
}
