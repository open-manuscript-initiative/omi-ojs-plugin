<?php
declare(strict_types=1);

namespace PKP\plugins {
    class GenericPlugin {
        public array $settings = ['studioUrl' => 'https://authoring.example.invalid/workspace', 'sharedSecret' => 'portable-fixture-only', 'installationId' => 'fixture-instance', 'tokenTtl' => 300];
        public function getPluginPath(): string { return 'plugins/generic/studioIntegration'; }
        public function getSetting(int $id, string $key): mixed { return $this->settings[$key] ?? null; }
    }
    class Hook { public const CONTINUE = false; }
}
namespace PKP\core {
    class PKPBaseController {}
    class APIRouter {
        public array $controllers = [];
        public function registerPluginApiControllers(array $controllers): void { $this->controllers = $controllers; }
    }
}
namespace APP\handler { class Handler { public function __construct() {} } }
namespace PKP\form { class Form {} }
namespace PKP\security {
    class Role { public const ROLE_ID_MANAGER = 16, ROLE_ID_SUB_EDITOR = 17, ROLE_ID_ASSISTANT = 4097, ROLE_ID_SITE_ADMIN = 1; }
}
namespace APP\core { class Application { public const ROUTE_API = 'api', SITE_CONTEXT_ID = 0; } }
namespace APP\facades {
    class Repo {
        public static function submission(): object {
            return new class {
                public function get(int $id, int $contextId): ?object { return $id === 220 && $contextId === 7 ? (object)[] : null; }
            };
        }
    }
}
namespace {
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    require dirname(__DIR__) . '/StudioIntegrationPlugin.php';
    $plugin = new \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin();
    // A worker/host may enter a hook with an unrelated current directory.
    $originalCwd = getcwd();
    $temporary = sys_get_temp_dir() . '/omi-portability-' . bin2hex(random_bytes(6));
    mkdir($temporary);
    try {
        chdir($temporary);
        $handler = null;
        $args = ['omiIntegration', 'launch', null, &$handler];
        $plugin->loadApiHandler('LoadHandler', $args);
        check($args[3] instanceof \APP\plugins\generic\studioIntegration\StudioIntegrationApiHandler, 'Launch handler depends on the process working directory');
        $router = new \PKP\core\APIRouter();
        $plugin->registerApiController('APIHandler::endpoints::plugin', $router);
        check(($router->controllers[0] ?? null) instanceof \APP\plugins\generic\studioIntegration\StudioIntegrationApiController, 'API controller depends on the process working directory');
        check($plugin->getInstallationError() === null, 'Complete plugin must be healthy from another working directory');
    } finally {
        chdir($originalCwd);
        rmdir($temporary);
    }
    foreach ([
        ['https://journal-a.example.invalid', '/index.php/alpha', 'alpha'],
        ['https://journal-b.example.invalid/ojs', '/index.php/beta', 'beta'],
        ['https://publisher.example.invalid:8443/journals/archive', '/index.php/review', 'review'],
        ['https://rewritten.example.invalid', '/journal-de', 'journal-de'],
    ] as [$base, $route, $contextPath]) {
        $request = new class($base, $route, $contextPath) {
            public function __construct(public string $base, public string $route, public string $contextPath) {}
            public function getBaseUrl(): string { return $this->base; }
            public function getContext(): object {
                return new class($this->contextPath) {
                    public function __construct(private string $path) {}
                    public function getId(): int { return 7; }
                    public function getPath(): string { return $this->path; }
                };
            }
            public function getUser(): object {
                return new class {
                    public function getId(): int { return 42; }
                    public function hasRole(array $roles, int $id): bool { return $id === 7 && in_array(16, $roles, true); }
                };
            }
            public function getDispatcher(): object {
                return new class($this->base, $this->route, $this->contextPath) {
                    public function __construct(private string $base, private string $route, private string $contextPath) {}
                    public function url($request, $type, $context, $handler): string {
                        check($type === 'api' && $context === $this->contextPath && $handler === 'omi-integration', 'Use the native API dispatcher and current journal');
                        return $this->base . $this->route . '/api/v1/omi-integration';
                    }
                };
            }
        };
        $launch = $plugin->createLaunchUrl($request, 220);
        check(str_starts_with($launch ?? '', 'https://authoring.example.invalid/workspace/integrations/ojs/launch?'), 'Preserve configured Studio origin and subdirectory');
        parse_str(parse_url($launch, PHP_URL_QUERY), $token);
        $claims = \APP\plugins\generic\studioIntegration\classes\Core\LaunchToken::verify($token['payload'], $token['signature'], 'portable-fixture-only', 7);
        check(($claims['externalBaseUrl'] ?? null) === $base, 'Preserve the current OJS origin, port and installation subdirectory');
        check($claims['apiBaseUrl'] === $base . $route . '/api/v1/omi-integration', 'Preserve native API routing including rewritten URLs');
        check($claims['context']['path'] === $contextPath && $claims['installationId'] === 'fixture-instance', 'Preserve journal and configured installation identity');
        check($plugin->createLaunchUrl($request, 999) === null, 'Portability must not bypass submission authorization');
    }
    echo "Working-directory-independent handlers and signed launch URLs on four origins/layouts passed.\n";
}
