#!/usr/bin/env bash
set -euo pipefail

php <<'PHP'
<?php

require 'tools/bootstrap.php';

// The PKP CLI bootstrap creates the application request and loads all generic
// plugins from this OJS checkout, including the plugin copied in by the PKP CI action.
new \\PKP\\cliTool\\CommandLineTool(['omi-plugin-compatibility-smoke']);

$pluginClass = \\APP\\plugins\\generic\\studioIntegration\\StudioIntegrationPlugin::class;
$plugin = null;

foreach (\\PKP\\plugins\\PluginRegistry::getPlugins('generic') as $candidate) {
    if ($candidate instanceof $pluginClass) {
        $plugin = $candidate;
        break;
    }
}

if (!$plugin instanceof $pluginClass) {
    throw new RuntimeException('OJS did not discover and register the OMI Studio plugin.');
}

$expectedPluginPath = 'plugins/generic/studioIntegration';
if ($plugin->getPluginPath() !== $expectedPluginPath) {
    throw new RuntimeException('Unexpected OJS plugin path: ' . $plugin->getPluginPath());
}

$pluginFiles = [
    'StudioIntegrationApiHandler.php' => \\APP\\plugins\\generic\\studioIntegration\\StudioIntegrationApiHandler::class,
    'StudioIntegrationApiController.php' => \\APP\\plugins\\generic\\studioIntegration\\StudioIntegrationApiController::class,
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

echo "OJS discovered the plugin and loaded its integration classes successfully.\\n";
PHP
