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
} elseif ($mode === 'settings') {
    require_once $root . '/StudioIntegrationPlugin.php';
    // Plugin settings belong to a real journal, not a fictitious context_id=0
    // (OJS 3.5 enforces a journal foreign key on plugin_settings).
    $contextId = \Illuminate\Support\Facades\DB::table('journals')->insertGetId([
        'path' => 'omi-settings-fixture', 'primary_locale' => 'en', 'enabled' => 1,
    ], 'journal_id');
    foreach (['supportedLocales', 'supportedFormLocales', 'supportedSubmissionLocales'] as $key) {
        \Illuminate\Support\Facades\DB::table('journal_settings')->insert([
            'journal_id' => $contextId, 'locale' => '', 'setting_name' => $key, 'setting_value' => json_encode(['en']),
        ]);
    }
    $_SERVER['PATH_INFO'] = '/omi-settings-fixture/management/settings/website';
    $request = \APP\core\Application::get()->getRequest();
    $router = new \PKP\core\PKPPageRouter();
    $router->setApplication(\APP\core\Application::get());
    $router->setDispatcher(\APP\core\Application::get()->getDispatcher());
    $request->setRouter($router);
    if ($request->getContext()?->getId() !== $contextId) throw new RuntimeException('Settings fixture did not resolve its native journal context');
    $plugin = new \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin();
    $plugin->register('generic', 'plugins/generic/studioIntegration', $contextId);
    $values = ['studioUrl' => 'https://studio.example.invalid', 'installationId' => 'ci-installation', 'sharedSecret' => 'ci-only-settings-secret', 'tokenTtl' => 600];
    foreach ($values as $key => $value) $plugin->updateSetting($contextId, $key, $value, $key === 'tokenTtl' ? 'int' : 'string');
    $snapshot = fn () => \Illuminate\Support\Facades\DB::table('plugin_settings')->where('plugin_name', $plugin->getName())->orderBy('setting_name')->get()->toJson();
    $before = $snapshot();
    $vars =& $request->getUserVars();
    $vars['verb'] = 'settings';
    unset($vars['save']);
    $checkForm = function ($message, bool $incomplete) use ($values): void {
        if (!$message->getStatus() || !is_string($message->getContent())) throw new RuntimeException('Settings modal requires a successful HTML response');
        $html = $message->getContent();
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        foreach ($values as $key => $value) {
            // Native FBV adds a random suffix to ids; field names are stable.
            $input = $xpath->query('//input[@name="' . $key . '"]')->item(0);
            if (!$input || $input->getAttribute('value') !== (string)$value) throw new RuntimeException('Saved settings field missing or changed: ' . $key);
            if ($input->hasAttribute('disabled') !== $incomplete) throw new RuntimeException('Incorrect settings editability: ' . $key);
        }
        if ($incomplete !== str_contains($html, 'PLUGIN_INSTALLATION_INCOMPLETE')) throw new RuntimeException('Settings warning does not match installation health');
        if (($xpath->query('//*[@type="submit"]')->length > 0) === $incomplete) throw new RuntimeException('Save control must be absent while incomplete');
    };
    $checkForm($plugin->manage([], $request), false);
    $adapter = $root . '/classes/Adapters/Ojs35Adapter.php';
    $bytes = file_get_contents($adapter);
    $template = $root . '/templates/settingsForm.tpl';
    $templateBytes = file_get_contents($template);
    try {
        unlink($adapter);
        $checkForm($plugin->manage([], $request), true);
        $vars['save'] = '1';
        $vars['studioUrl'] = 'https://must-not-save.example.invalid';
        $blocked = $plugin->manage([], $request);
        if ($blocked->getStatus() || !is_string($blocked->getContent()) || $before !== $snapshot()) throw new RuntimeException('Incomplete installation must not write settings');
        unset($vars['save']);
        unlink($template);
        $fallback = $plugin->manage([], $request);
        if (!$fallback->getStatus() || !is_string($fallback->getContent()) || !str_contains($fallback->getContent(), 'role="alert"')) throw new RuntimeException('Missing template must render a diagnostic instead of a blank dialog');
    } finally {
        file_put_contents($adapter, $bytes);
        file_put_contents($template, $templateBytes);
        unset($vars['save'], $vars['studioUrl']);
    }
    $checkForm($plugin->manage([], $request), false);
    if ($before !== $snapshot()) throw new RuntimeException('Opening/recovering settings changed database values');
    echo "Native Smarty settings rendering, saved-value preservation, blocked writes and recovery passed.\n";
} elseif ($mode === 'permissions') {
    require_once $root . '/StudioIntegrationPlugin.php';
    require_once $root . '/StudioIntegrationApiController.php';
    $plugin = \PKP\plugins\PluginRegistry::getPlugin('generic', 'studiointegrationplugin');
    if (!($plugin instanceof \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin)) {
        throw new RuntimeException('Native plugin registry did not discover the installed plugin');
    }
    $controller = new \APP\plugins\generic\studioIntegration\StudioIntegrationApiController($plugin);
    $middlewareName = $controller->getRouteGroupMiddleware()[1];
    if (!is_string($middlewareName)) throw new RuntimeException('PKP middleware inspection requires a class name');
    // Resolve the middleware through Laravel just as the API router does, then
    // invoke handle rather than treating the class name as a global function.
    $middleware = [app()->make($middlewareName), 'handle'];
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
    throw new InvalidArgumentException('Expected initialize, install, install-candidate, upgrade, settings or permissions mode');
}
