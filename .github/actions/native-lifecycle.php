<?php
declare(strict_types=1);

// Destructive fixture operations are restricted to the disposable PKP CI app.
require 'tools/bootstrap.php';
new \PKP\cliTool\CommandLineTool(['omi-native-install-fixture']);
if (getenv('OMI_NATIVE_INSTALL_TEST') !== '1'
    || realpath('.') !== getenv('OMI_NATIVE_TEST_APP')
    || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
    throw new RuntimeException('Run only as a non-root user in the explicitly configured disposable ojs-ci fixture.');
}
$mode = $argv[1] ?? '';
if ($mode === 'initialize') {
    if (\PKP\config\Config::getVar('general', 'installed')) throw new RuntimeException('Refusing to reinitialize an installed OJS application');
    // PKP's composite action creates an empty ojs-ci database and a template
    // config. A plugin lifecycle test needs a genuinely installed OJS schema/site.
    $installer = new class([
        'locale' => 'en', 'additionalLocales' => ['en'],
        'filesDir' => getcwd() . '/files', 'timeZone' => 'UTC',
        'adminUsername' => 'omi_ci_admin', 'adminPassword' => 'Only-a-disposable-CI-fixture-123!',
        'adminEmail' => 'omi-ci@example.invalid', 'databaseDriver' => 'mysqli',
        'databaseHost' => 'localhost', 'databasePort' => 3306,
        'databaseUsername' => 'ojs-ci', 'databasePassword' => 'ojs-ci', 'databaseName' => 'ojs-ci',
        'oaiRepositoryId' => 'omi-ci.example.invalid', 'enableBeacon' => false,
    ]) extends \APP\install\Install {
        // External reference-data downloads do not participate in plugin install.
        public function updateRorRegistryDataset(): bool { return true; }
        public function downloadIPGeoDB(): bool { return true; }
    };
    if (!$installer->execute() || !$installer->wroteConfig()) {
        throw new RuntimeException('Could not initialize disposable OJS: ' . $installer->getErrorString());
    }
    echo "Initialized disposable OJS schema and site as an unprivileged user.\n";
    exit;
}
if (\PKP\config\Config::getVar('database', 'name') !== 'ojs-ci'
    || !\PKP\config\Config::getVar('general', 'installed')) {
    throw new RuntimeException('Lifecycle operations require the installed disposable ojs-ci database');
}
$root = getcwd() . '/plugins/generic/studioIntegration';
$helper = new \PKP\plugins\PluginHelper();
$manager = new \PKP\file\FileManager();
$dao = \PKP\db\DAORegistry::getDAO('VersionDAO');
$package = getenv($mode === 'install' ? 'OMI_BASELINE_ARCHIVE' : 'PKP_TEST_PACKAGE_ARCHIVE');
if (in_array($mode, ['install', 'install-candidate', 'upgrade'], true)) {
    if (!$package || !is_file($package)) throw new RuntimeException('Missing lifecycle test archive');
    // Web uploads arrive as extensionless temporary files; exercise PKP's
    // filename/extension normalization rather than passing an already named tar.
    $uploaded = tempnam(sys_get_temp_dir(), 'omi-upload-');
    if (!$uploaded || !copy($package, $uploaded)) throw new RuntimeException('Could not prepare upload fixture');
    try {
        if ($mode !== 'upgrade') {
            \Illuminate\Support\Facades\DB::table('versions')->where('product_type', 'plugins.generic')->where('product', 'studioIntegration')->delete();
            $manager->rmtree($root);
            if (is_dir($root)) throw new RuntimeException('Fixture plugin directory could not be removed');
            $version = $helper->installPlugin($uploaded, basename($package));
        } else {
            if (!$dao->getCurrentVersion('plugins.generic', 'studioIntegration')) throw new RuntimeException('Baseline plugin was not installed');
            $version = $helper->upgradePlugin('generic', 'studioIntegration', $uploaded, basename($package));
        }
    } finally {
        unlink($uploaded);
    }
    require_once $root . '/PackageIntegrity.php';
    \APP\plugins\generic\studioIntegration\PackageIntegrity::assertComplete($root);
    $installed = $dao->getCurrentVersion('plugins.generic', 'studioIntegration');
    if (!$installed || $installed->compare($version) !== 0) throw new RuntimeException('Native installer did not record the installed plugin version');
    foreach (file($root . '/PACKAGE-CONTENTS.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$hash, $relative] = explode('  ', $line, 2);
        if (!is_file($root . '/' . $relative) || !hash_equals($hash, hash_file('sha256', $root . '/' . $relative))) {
            throw new RuntimeException('Native ' . $mode . ' changed or lost ' . $relative);
        }
    }
    echo 'Native ' . $mode . ' passed with version ' . $installed->getVersionString(false) . ' under UID ' . posix_geteuid() . ".\n";
} elseif ($mode === 'permissions') {
    require_once $root . '/StudioIntegrationPlugin.php';
    require_once $root . '/StudioIntegrationApiController.php';
    $plugin = new \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin();
    $controller = new \APP\plugins\generic\studioIntegration\StudioIntegrationApiController($plugin);
    $middleware = $controller->getRouteGroupMiddleware()[1];
    $request = \Illuminate\Http\Request::create('/');
    $calls = 0;
    $next = function ($request) use (&$calls) { $calls++; return response()->json(['ok' => true]); };
    $adapter = $root . '/classes/Adapters/Ojs35Adapter.php';
    $parent = dirname($root);
    $fileMode = fileperms($adapter) & 0777;
    $parentMode = fileperms($parent) & 0777;
    try {
        chmod($adapter, 0000);
        clearstatcache();
        if (is_readable($adapter)) throw new RuntimeException('Permission test is ineffective: adapter remains readable');
        $response = $middleware($request, $next);
        if ($response->getStatusCode() !== 503 || $calls !== 0
            || $response->getData(true)['error']['code'] !== 'PLUGIN_INSTALLATION_INCOMPLETE') {
            throw new RuntimeException('Unreadable adapter did not stop the API with a controlled diagnostic');
        }
        chmod($adapter, $fileMode);
        clearstatcache();
        if ($middleware($request, $next)->getStatusCode() !== 200 || $calls !== 1) throw new RuntimeException('Restored permissions did not recover the API');
        chmod($parent, 0555);
        clearstatcache();
        if (is_writable($parent)) throw new RuntimeException('Permission test is ineffective: parent remains writable');
        // A fully installed read-only deployment must still run. Write access
        // is an installer requirement, never an application/runtime requirement.
        if ($middleware($request, $next)->getStatusCode() !== 200) throw new RuntimeException('Read-only plugin parent incorrectly disabled runtime');
        $target = $parent . '/omi-permission-fixture';
        if (@$manager->copyDir($root, $target) || is_dir($target)) {
            $manager->rmtree($target);
            throw new RuntimeException('Native PHP copy unexpectedly wrote to a protected plugin parent');
        }
        echo "Non-root unreadable-file rejection, read-only runtime and permission recovery passed.\n";
    } finally {
        chmod($adapter, $fileMode);
        chmod($parent, $parentMode);
    }
} else {
    throw new InvalidArgumentException('Expected initialize, install, install-candidate, upgrade or permissions mode');
}
