#!/usr/bin/env bash
set -euo pipefail

php <<'PHP'
<?php

require 'tools/bootstrap.php';

// Check the actual checkout so a floating/wrong PKP ref cannot produce green
// evidence labelled as a different supported release.
$expectedTag = getenv('PKP_EXPECTED_VERSION');
if (!$expectedTag) {
    throw new RuntimeException('PKP_EXPECTED_VERSION must name the tested release tag.');
}
$expectedVersion = str_replace(['_', '-'], '.', $expectedTag);
$actualVersion = (string)simplexml_load_file('dbscripts/xml/version.xml')->release;
if ($actualVersion !== $expectedVersion) {
    throw new RuntimeException('PKP version mismatch: expected ' . $expectedVersion . ', got ' . $actualVersion);
}
echo 'Testing PKP release ' . $actualVersion . PHP_EOL;


// The PKP CLI bootstrap creates the application request and loads generic
// plugins from this OJS checkout, including the plugin copied in by the PKP CI action.
new \PKP\cliTool\CommandLineTool(['omi-plugin-compatibility-smoke']);

$pluginClass = \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin::class;
$plugin = null;

foreach (\PKP\plugins\PluginRegistry::getPlugins('generic') as $candidate) {
    if ($candidate instanceof $pluginClass) {
        $plugin = $candidate;
        break;
    }
}

if (!($plugin instanceof $pluginClass)) {
    throw new RuntimeException('OJS did not discover and register the OMI Studio plugin.');
}

$expectedPluginPath = 'plugins/generic/studioIntegration';
if ($plugin->getPluginPath() !== $expectedPluginPath) {
    throw new RuntimeException('Unexpected OJS plugin path: ' . $plugin->getPluginPath());
}

// Do not preload helpers here: OJS must make them available through its own
// modern namespaced plugin discovery path, without relying on index.php.
$helperPrefix = '\\APP\\plugins\\generic\\studioIntegration\\classes\\';
foreach ([
    'Adapters\\Ojs35Adapter', 'Core\\Base64Url', 'Core\\LaunchToken',
    'Core\\ApiResponse', 'Core\\HtmlGalleyDocument',
    'Core\\PublicationArtifactDocument', 'Core\\WorkflowFileDocument',
    'Core\\WorkflowSnapshot', 'Core\\MetadataReconciliation',
] as $helperName) {
    if (!class_exists($helperPrefix . $helperName)) {
        throw new RuntimeException('OJS plugin discovery missed helper: ' . $helperName);
    }
}

// Exercise the real native Installer code action for both descriptors, without
// running database migrations or changing the test journal's installed version.
$nativeInstaller = (new ReflectionClass(\APP\install\Install::class))->newInstanceWithoutConstructor();
foreach (['install.xml', 'upgrade.xml'] as $descriptor) {
    $xml = simplexml_load_file($plugin->getPluginPath() . '/' . $descriptor);
    $attributes = [];
    foreach ($xml->code->attributes() as $key => $value) $attributes[$key] = (string)$value;
    if (!$nativeInstaller->executeAction(['type' => 'code', 'file' => $attributes['file'], 'attr' => $attributes])) {
        throw new RuntimeException('Native installer rejected complete package: ' . $descriptor);
    }
}

// Exercise native PluginHelper extraction and FileManager copying as well as
// the standalone archive checks. No production/database version is modified.
$roundTripRoot = sys_get_temp_dir() . '/omi-pkp-package-' . bin2hex(random_bytes(8));
mkdir($roundTripRoot);
$fileManager = new \PKP\file\FileManager();
try {
    $archivePath = getenv('PKP_TEST_PACKAGE_ARCHIVE');
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('PKP_TEST_PACKAGE_ARCHIVE must identify the canonical release package');
    }
    $sourceRoot = $plugin->getPluginPath();
    $extractor = new ReflectionMethod(\PKP\plugins\PluginHelper::class, 'extractPlugin');
    $extractor->invoke(new \PKP\plugins\PluginHelper(), $archivePath, basename($archivePath), function (string $extracted) use ($fileManager, $roundTripRoot, $sourceRoot): void {
        // Verify the same canonical archive tested/published by release CI.
        \APP\plugins\generic\studioIntegration\PackageIntegrity::assertComplete($extracted);
        $copied = $roundTripRoot . '/installed';
        if (!$fileManager->copyDir($extracted, $copied)) throw new RuntimeException('Native plugin copy failed');
        \APP\plugins\generic\studioIntegration\PackageIntegrity::assertComplete($copied);
        echo "Native PKP extraction/copy preserved the complete release package.\n";
        $adapter = '/classes/Adapters/Ojs35Adapter.php';
        if (hash_file('sha256', $sourceRoot . $adapter) !== hash_file('sha256', $copied . $adapter)) {
            throw new RuntimeException('Native installation changed adapter bytes');
        }
        unlink($copied . $adapter);
        try {
            \APP\plugins\generic\studioIntegration\PackageIntegrity::assertComplete($copied);
            throw new LogicException('Incomplete native copy was accepted');
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'classes/Adapters/Ojs35Adapter.php')) throw $e;
        }
    });
} finally {
    $fileManager->rmtree($roundTripRoot);
}

$pluginFiles = [
    'StudioIntegrationApiHandler.php' => \APP\plugins\generic\studioIntegration\StudioIntegrationApiHandler::class,
    'StudioIntegrationApiController.php' => \APP\plugins\generic\studioIntegration\StudioIntegrationApiController::class,
];

foreach ($pluginFiles as $file => $className) {
    $path = $plugin->getPluginPath() . '/' . $file;
    if (!is_file($path)) {
        throw new RuntimeException('Expected plugin integration file is missing: ' . $path);
    }
    require_once $path;
    if (!class_exists($className, false)) {
        throw new RuntimeException('PKP could not load integration class: ' . $className);
    }
}

echo "OJS discovered the plugin and loaded its integration classes successfully.\n";
PHP
