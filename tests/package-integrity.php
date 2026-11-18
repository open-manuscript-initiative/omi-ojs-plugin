<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/PackageIntegrity.php';
use APP\plugins\generic\studioIntegration\PackageIntegrity;
$root = dirname(__DIR__);
PackageIntegrity::assertComplete($root);
if (!PackageIntegrity::install(new stdClass(), [])) throw new RuntimeException('Installer callback failed');
foreach (['install.xml', 'upgrade.xml'] as $name) {
    $xml = simplexml_load_file($root . '/' . $name);
    if ((string)$xml->code['file'] !== 'plugins/generic/studioIntegration/PackageIntegrity.php'
        || (string)$xml->code['class'] !== PackageIntegrity::class
        || (string)$xml->code['function'] !== 'install') {
        throw new RuntimeException('Native installation guard is missing from ' . $name);
    }
}
$temp = sys_get_temp_dir() . '/omi-package-integrity-' . bin2hex(random_bytes(8));
mkdir($temp);
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($walk as $entry) {
    $relative = substr($entry->getPathname(), strlen($root) + 1);
    if ($entry->isFile() && !str_starts_with($relative, '.')) {
        $destination = $temp . '/' . $relative;
        if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0755, true);
        copy($entry->getPathname(), $destination);
    }
}
try {
    $adapter = $temp . '/classes/Adapters/Ojs35Adapter.php';
    $bytes = file_get_contents($adapter);
    foreach (['missing', 'empty'] as $mode) {
        if ($mode === 'missing') unlink($adapter); else file_put_contents($adapter, '');
        try {
            PackageIntegrity::assertComplete($temp);
            throw new LogicException('Incomplete package passed installation guard: ' . $mode);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'classes/Adapters/Ojs35Adapter.php')) throw $e;
        }
        file_put_contents($adapter, $bytes);
        PackageIntegrity::assertComplete($temp);
    }
    echo "Package installation and upgrade integrity checks passed.\n";
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($temp);
}
