<?php
require_once __DIR__ . '/../classes/Core/WorkflowFileDocument.php';

use APP\plugins\generic\studioIntegration\classes\Core\WorkflowFileDocument;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$bytes = "PK\x03\x04OMI workflow file";
$decoded = WorkflowFileDocument::decode(
    'revision.docx',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    base64_encode($bytes)
);

check($decoded['fileName'] === 'revision.docx', 'File name was not preserved.');
check($decoded['extension'] === 'docx', 'File extension was not detected.');
check($decoded['byteLength'] === strlen($bytes), 'Byte length mismatch.');
check($decoded['sha256'] === hash('sha256', $bytes), 'SHA-256 mismatch.');

$invalid = [
    ['../revision.docx', 'application/octet-stream', base64_encode('x')],
    ['revision.docx', "application/octet-stream\r\nX-Evil: 1", base64_encode('x')],
    ['revision.docx', 'application/octet-stream', 'not base64!'],
];

foreach ($invalid as [$name, $type, $payload]) {
    try {
        WorkflowFileDocument::decode($name, $type, $payload);
        check(false, 'Invalid workflow file input was accepted.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

echo "Workflow file document checks passed\n";
