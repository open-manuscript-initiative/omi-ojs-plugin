<?php
declare(strict_types=1);

namespace PKP\plugins { class GenericPlugin {} }
namespace PKP\core {
    class PKPBaseController {}
    class JSONMessage {
        public function __construct(public bool $status, public array $content) {}
    }
}
namespace APP\handler { class Handler { public function __construct() {} } }
namespace Illuminate\Http {
    class Request {}
    class JsonResponse {
        public function __construct(public array $data, public int $status) {}
    }
}
namespace {
    function response(): object {
        return new class {
            public function json(array $data, int $status = 200): \Illuminate\Http\JsonResponse {
                return new \Illuminate\Http\JsonResponse($data, $status);
            }
        };
    }
    function verify(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
    }
    $source = dirname(__DIR__);
    $root = sys_get_temp_dir() . '/omi-incomplete-' . bin2hex(random_bytes(8));
    mkdir($root);
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        if (!$file->isFile() || str_starts_with($relative, '.')) continue;
        $target = $root . '/' . $relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
        copy($file->getPathname(), $target);
    }
    // Exercise the real entry points against a separate installed-file fixture.
    require $root . '/StudioIntegrationPlugin.php';
    require $root . '/StudioIntegrationApiController.php';
    require $root . '/StudioIntegrationApiHandler.php';
    $plugin = new \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin();
    $controller = new \APP\plugins\generic\studioIntegration\StudioIntegrationApiController($plugin);
    $middleware = $controller->getRouteGroupMiddleware();
    verify($middleware[0] === 'has.context', 'Native context middleware must remain');
    $guard = $middleware[1];
    $request = new \Illuminate\Http\Request();
    $calls = 0;
    $next = function ($request) use (&$calls) { $calls++; return response()->json(['healthy' => true]); };
    $adapter = $root . '/classes/Adapters/Ojs35Adapter.php';
    $bytes = file_get_contents($adapter);
    $log = $root . '/error.log';
    ini_set('error_log', $log);
    try {
        $guardFile = $root . '/PackageIntegrity.php';
        $guardBytes = file_get_contents($guardFile);
        // Test before the guard class has ever been loaded in this process.
        file_put_contents($guardFile, '');
        verify($plugin->getInstallationError() !== null, 'Empty guard must not cause a class-not-found fatal');
        unlink($guardFile);
        verify($plugin->getInstallationError() !== null, 'Missing guard must remain diagnosable');
        file_put_contents($guardFile, $guardBytes);
        verify($plugin->getInstallationError() === null, 'Complete package must be healthy');
        verify($guard($request, $next)->status === 200 && $calls === 1, 'Healthy API must continue');
        // Read-only runtime files are valid; only an upgrade needs write access.
        chmod($adapter, 0444);
        verify($plugin->getInstallationError() === null, 'Read-only deployments must be supported');
        chmod($adapter, 0644);
        foreach (['missing', 'empty'] as $mode) {
            if ($mode === 'missing') unlink($adapter); else file_put_contents($adapter, '');
            $response = $guard($request, $next);
            verify($response->status === 503 && $calls === 1, 'Broken API must stop before endpoint/helper use');
            verify($response->data['error']['code'] === 'PLUGIN_INSTALLATION_INCOMPLETE', 'Stable diagnostic code required');
            verify(!str_contains(json_encode($response->data), $root), 'Do not disclose installation paths');
            $handler = new \APP\plugins\generic\studioIntegration\StudioIntegrationApiHandler($plugin);
            $launch = $handler->launch([], new class {
                public function getUserVar(string $key): string { return $key === 'submissionId' ? '220' : '1'; }
            });
            verify(!$launch->status && http_response_code() === 503, 'Incomplete launch must not redirect or create an assertion');
            verify($launch->content['error']['code'] === 'PLUGIN_INSTALLATION_INCOMPLETE', 'Launch diagnostic must match API');
            file_put_contents($adapter, $bytes);
        }
        $description = file_get_contents($log);
        verify(str_contains($description, 'Ojs35Adapter.php (missing)') && str_contains($description, 'Ojs35Adapter.php (empty)'), 'Admin log must distinguish file failures');
        verify($guard($request, $next)->status === 200 && $calls === 2, 'Restoring files must recover without process restart');
        echo "Incomplete installation API/launch and recovery checks passed.\n";
    } finally {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($root);
    }
}
