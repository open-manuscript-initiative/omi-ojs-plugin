<?php
require __DIR__ . '/../classes/Core/HtmlGalleyDocument.php';
use APP\plugins\generic\studioIntegration\classes\Core\HtmlGalleyDocument;

$valid = '<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Minta</title><style>body { color: #333; }</style></head><body><h1>Átadás</h1><a href="#note">Jegyzet</a><p id="note">Szöveg</p><img alt="Minta" src="data:image/png;base64,YQ=="></body></html>';
HtmlGalleyDocument::validate($valid);
foreach ([
    str_replace('</body>', '<script>alert(1)</script></body>', $valid),
    str_replace('<h1>', '<h1 onclick="alert(1)">', $valid),
    str_replace('#note', 'javascript:alert(1)', $valid),
    str_replace('data:image/png;base64,YQ==', 'https://example.test/image.png', $valid),
    str_replace('color: #333;', 'background: url(https://example.test/);', $valid),
    str_replace('color: #333;', '@import "https://example.test/a.css";', $valid),
    str_replace('</head>', '<meta http-equiv="refresh" content="0;url=https://example.test"></head>', $valid),
    str_repeat('x', 8 * 1024 * 1024 + 1),
] as $invalid) {
    try { HtmlGalleyDocument::validate($invalid); throw new RuntimeException('Unsafe document accepted'); }
    catch (InvalidArgumentException $expected) {}
}
if (HtmlGalleyDocument::path('study', 'hu') !== HtmlGalleyDocument::path('study', 'hu')
    || HtmlGalleyDocument::path('study', 'hu') === HtmlGalleyDocument::path('study', 'en')) {
    throw new RuntimeException('Unstable or non-localized galley identity');
}
if (isset($argv[1])) HtmlGalleyDocument::validate(file_get_contents($argv[1]));
echo "HTML document checks passed\n";
