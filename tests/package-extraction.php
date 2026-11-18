<?php
declare(strict_types=1);
// Match PluginHelper::extractPlugin: use PHP PharData, not only GNU tar/unzip.
require_once dirname(__DIR__) . '/PackageIntegrity.php';
use APP\plugins\generic\studioIntegration\PackageIntegrity;
if (empty($argv[1])) throw new InvalidArgumentException('Pass the generated ZIP or TAR.GZ archive.');
$archive = realpath($argv[1]);
$temp = sys_get_temp_dir() . '/omi-native-extraction-' . bin2hex(random_bytes(8));
mkdir($temp);
try {
    (new PharData($archive))->extractTo($temp, null, true);
    $root = $temp . '/studioIntegration';
    PackageIntegrity::assertComplete($root);
    $manifest = file($root . '/PACKAGE-CONTENTS.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$manifest) throw new RuntimeException('Package inventory is missing.');
    $count = 0;
    $adapterSeen = false;
    foreach ($manifest as $line) {
        if (!preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9_.\/-]+)$/D', $line, $match)
            || str_starts_with($match[2], '/') || in_array('..', explode('/', $match[2]), true)) {
            throw new RuntimeException('Invalid package inventory entry.');
        }
        $relative = $match[2];
        if (!is_file($root . '/' . $relative) || !hash_equals($match[1], hash_file('sha256', $root . '/' . $relative))) {
            throw new RuntimeException('PHP extraction changed or lost: ' . $relative);
        }
        if ($relative === 'classes/Adapters/Ojs35Adapter.php') $adapterSeen = true;
        $count++;
    }
    if (!$adapterSeen) throw new RuntimeException('Adapter omitted from package inventory.');
    // A directory that exists but contains no adapter must fail, matching the incident.
    unlink($root . '/classes/Adapters/Ojs35Adapter.php');
    try {
        PackageIntegrity::assertComplete($root);
        throw new LogicException('Empty adapter directory was accepted.');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'classes/Adapters/Ojs35Adapter.php')) throw $e;
    }
    echo "PHP/PKP archive extraction verified: " . $count . " files; missing-adapter rejection passed.\n";
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($temp);
}
