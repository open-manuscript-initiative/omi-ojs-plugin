<?php
require __DIR__ . '/../classes/Core/HtmlGalleyDocument.php';
require __DIR__ . '/../classes/Core/PublicationArtifactDocument.php';

use APP\plugins\generic\studioIntegration\classes\Core\PublicationArtifactDocument;

function canonical(mixed $value): string
{
    if ($value === null) return 'null';
    if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) throw new RuntimeException('encode failed');
        return $encoded;
    }
    if (!is_array($value)) throw new RuntimeException('unsupported canonical value');
    if (array_is_list($value)) {
        return '[' . implode(',', array_map('canonical', $value)) . ']';
    }
    ksort($value, SORT_STRING);
    $parts = [];
    foreach ($value as $key => $item) {
        $parts[] = json_encode((string)$key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ':' . canonical($item);
    }
    return '{' . implode(',', $parts) . '}';
}

function buildManifest(
    string $format,
    string $mediaType,
    string $fileName,
    string $bytes,
    string $manuscriptId = 'manuscript-1'
): array {
    $output = [
        'format' => $format,
        'mediaType' => $mediaType,
        'fileName' => $fileName,
        'byteLength' => strlen($bytes),
        'digest' => ['algorithm' => 'sha256', 'value' => hash('sha256', $bytes)],
    ];
    $manifest = [
        'model' => 'omi-publication-build',
        'version' => '0.1.0',
        'manuscript' => [
            'id' => $manuscriptId,
            'revisionId' => 'revision-1',
            'stateDigest' => [
                'algorithm' => 'sha256',
                'value' => str_repeat('1', 64),
                'canonicalization' => 'omi-manuscript-state-json-v1',
            ],
        ],
        'profile' => [
            'id' => 'default',
            'version' => '1.0.0',
            'digest' => [
                'algorithm' => 'sha256',
                'value' => str_repeat('2', 64),
                'canonicalization' => 'omi-publication-profile-json-v1',
            ],
        ],
        'output' => $output,
        ...(($format === 'pdf-print' || $format === 'pdf-interactive') ? [
            'rendererInput' => [
                'mediaType' => 'text/html;charset=utf-8',
                'byteLength' => 31,
                'digest' => [
                    'algorithm' => 'sha256',
                    'value' => hash('sha256', '<html><body>PDF</body></html>'),
                ],
            ],
        ] : []),
        'generator' => [
            'application' => 'open-manuscript-studio',
            'applicationVersion' => '0.2.0-beta.1',
            'applicationBuild' => '1030',
            'applicationCommit' => str_repeat('a', 40),
            'renderer' => $format === 'pdf-print' || $format === 'pdf-interactive'
                ? 'vivliostyle-cli'
                : 'open-manuscript-studio',
            'rendererVersion' => $format === 'pdf-print' || $format === 'pdf-interactive'
                ? '11.0.4'
                : '0.2.0-beta.1',
        ],
    ];
    $identity = $manifest;
    $manifest['id'] = 'urn:omi:publication-build:sha256:' . hash('sha256', canonical($identity));
    $manifest['createdAt'] = '2026-09-18T11:30:00.000Z';

    // Match Studio field order only for readability; canonical verification is order-independent.
    return [
        'model' => $manifest['model'],
        'version' => $manifest['version'],
        'id' => $manifest['id'],
        'createdAt' => $manifest['createdAt'],
        'manuscript' => $manifest['manuscript'],
        'profile' => $manifest['profile'],
        'output' => $manifest['output'],
        ...(isset($manifest['rendererInput']) ? ['rendererInput' => $manifest['rendererInput']] : []),
        'generator' => $manifest['generator'],
    ];
}

function expectInvalid(callable $callback, string $message): void
{
    try {
        $callback();
        throw new RuntimeException($message);
    } catch (InvalidArgumentException $expected) {
    }
}

$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Article</title><style>body{font-family:serif}</style></head><body><h1>Article</h1><p>Text</p></body></html>';
$htmlBuild = buildManifest('html', 'text/html;charset=utf-8', 'article.html', $html);
$htmlResult = PublicationArtifactDocument::validateTransfer(
    'html',
    'text/html;charset=utf-8',
    'article.html',
    base64_encode($html),
    $htmlBuild,
    'manuscript-1'
);
if ($htmlResult['sha256'] !== hash('sha256', $html) || !$htmlResult['buildId']) {
    throw new RuntimeException('HTML artifact provenance verification failed');
}

$jats = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<!DOCTYPE article SYSTEM "https://jats.nlm.nih.gov/articleauthoring/1.4/JATS-articleauthoring1-4-mathml3.dtd">' . "\n"
    . '<article dtd-version="1.4" xml:lang="en"><front><article-meta><title-group><article-title>Article</article-title></title-group></article-meta></front><body><p>Text</p></body></article>';
$jatsBuild = buildManifest('jats', 'application/xml', 'article.jats.xml', $jats);
PublicationArtifactDocument::validateTransfer(
    'jats',
    'application/xml',
    'article.jats.xml',
    base64_encode($jats),
    $jatsBuild,
    'manuscript-1'
);

$pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
$pdfBuild = buildManifest('pdf-print', 'application/pdf', 'article.pdf', $pdf);
PublicationArtifactDocument::validateTransfer(
    'pdf-print',
    'application/pdf',
    'article.pdf',
    base64_encode($pdf),
    $pdfBuild,
    'manuscript-1'
);

$interactiveBuild = buildManifest('pdf-interactive', 'application/pdf', 'article-interactive.pdf', $pdf);
PublicationArtifactDocument::validateTransfer(
    'pdf-interactive',
    'application/pdf',
    'article-interactive.pdf',
    base64_encode($pdf),
    $interactiveBuild,
    'manuscript-1'
);

$tampered = $htmlBuild;
$tampered['output']['digest']['value'] = str_repeat('0', 64);
expectInvalid(
    fn() => PublicationArtifactDocument::validateTransfer(
        'html',
        'text/html;charset=utf-8',
        'article.html',
        base64_encode($html),
        $tampered,
        'manuscript-1'
    ),
    'Tampered artifact digest accepted'
);

$tamperedId = $htmlBuild;
$tamperedId['id'] = 'urn:omi:publication-build:sha256:' . str_repeat('f', 64);
expectInvalid(
    fn() => PublicationArtifactDocument::validateTransfer(
        'html',
        'text/html;charset=utf-8',
        'article.html',
        base64_encode($html),
        $tamperedId,
        'manuscript-1'
    ),
    'Tampered build id accepted'
);

expectInvalid(
    fn() => PublicationArtifactDocument::validateTransfer(
        'html',
        'text/html;charset=utf-8',
        'article.html',
        base64_encode(str_replace('<p>Text</p>', '<p>Changed</p>', $html)),
        $htmlBuild,
        'manuscript-1'
    ),
    'Changed artifact bytes accepted'
);

expectInvalid(
    fn() => PublicationArtifactDocument::validateTransfer(
        'html',
        'application/xml',
        'article.html',
        base64_encode($html),
        $htmlBuild,
        'manuscript-1'
    ),
    'Media-type mismatch accepted'
);

expectInvalid(
    fn() => PublicationArtifactDocument::validateTransfer(
        'html',
        'text/html',
        '../article.html',
        base64_encode($html),
        $htmlBuild,
        'manuscript-1'
    ),
    'Unsafe filename accepted'
);

$unsafeJats = str_replace(
    '<!DOCTYPE article SYSTEM "https://jats.nlm.nih.gov/articleauthoring/1.4/JATS-articleauthoring1-4-mathml3.dtd">',
    '<!DOCTYPE article SYSTEM "https://example.test/evil.dtd">',
    $jats
);
$unsafeJatsBuild = buildManifest('jats', 'application/xml', 'article.jats.xml', $unsafeJats);
expectInvalid(
    fn() => PublicationArtifactDocument::validateTransfer(
        'jats',
        'application/xml',
        'article.jats.xml',
        base64_encode($unsafeJats),
        $unsafeJatsBuild,
        'manuscript-1'
    ),
    'Unexpected JATS DTD accepted'
);

$entityJats = str_replace(
    '<article dtd-version="1.4"',
    '<!ENTITY xxe "bad"><article dtd-version="1.4"',
    $jats
);
$entityBuild = buildManifest('jats', 'application/xml', 'article.jats.xml', $entityJats);
expectInvalid(
    fn() => PublicationArtifactDocument::validateTransfer(
        'jats',
        'application/xml',
        'article.jats.xml',
        base64_encode($entityJats),
        $entityBuild,
        'manuscript-1'
    ),
    'JATS entity declaration accepted'
);

$brokenPdf = "%PDF-1.7\nno eof";
$brokenPdfBuild = buildManifest('pdf-print', 'application/pdf', 'broken.pdf', $brokenPdf);
expectInvalid(
    fn() => PublicationArtifactDocument::validateTransfer(
        'pdf-print',
        'application/pdf',
        'broken.pdf',
        base64_encode($brokenPdf),
        $brokenPdfBuild,
        'manuscript-1'
    ),
    'Incomplete PDF accepted'
);

if (
    PublicationArtifactDocument::path('study', 'hu', 'jats') !==
        PublicationArtifactDocument::path('study', 'hu', 'jats') ||
    PublicationArtifactDocument::path('study', 'hu', 'jats') ===
        PublicationArtifactDocument::path('study', 'en', 'jats') ||
    PublicationArtifactDocument::path('study', 'hu', 'jats') ===
        PublicationArtifactDocument::path('study', 'hu', 'pdf-print')
) {
    throw new RuntimeException('Publication artifact galley identity is unstable');
}

$formats = PublicationArtifactDocument::formats(false);
$htmlFormat = array_values(array_filter($formats, fn(array $item): bool => $item['id'] === 'html'))[0] ?? null;
if (!$htmlFormat || $htmlFormat['available'] !== false) {
    throw new RuntimeException('HTML reader availability was not reflected in capabilities');
}

echo "Publication artifact document checks passed\n";
