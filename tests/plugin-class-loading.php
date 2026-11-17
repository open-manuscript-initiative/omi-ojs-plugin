<?php
declare(strict_types=1);

// Reproduce OJS 3.5's modern PluginRegistry path: load the namespaced plugin
// class directly, without running index.php or preloading any plugin helpers.
namespace PKP\plugins { class GenericPlugin {} }
namespace PKP\form { class Form {} }
namespace PKP\core { class PKPBaseController {} }
namespace {
    $root = dirname(__DIR__);
    $entry = ($argv[1] ?? '') === 'controller'
        ? '/StudioIntegrationApiController.php' : '/StudioIntegrationPlugin.php';
    require $root . $entry;
    $prefix = 'APP\\plugins\\generic\\studioIntegration\\';
    foreach ([
        'classes\\Adapters\\Ojs35Adapter',
        'classes\\Core\\Base64Url', 'classes\\Core\\LaunchToken',
        'classes\\Core\\ApiResponse', 'classes\\Core\\HtmlGalleyDocument',
        'classes\\Core\\PublicationArtifactDocument',
        'classes\\Core\\WorkflowFileDocument', 'classes\\Core\\WorkflowSnapshot',
        'classes\\Core\\MetadataReconciliation',
    ] as $relative) {
        if (!class_exists($prefix . $relative)) {
            throw new \RuntimeException('Direct plugin loading missed ' . $relative);
        }
    }
    $adapterClass = $prefix . 'classes\\Adapters\\Ojs35Adapter';
    $adapter = new $adapterClass();
    $submission = new class {
        public function getCurrentPublication(): ?object { return null; }
        public function getId(): int { return 220; }
        public function getData(string $key): mixed {
            return ['locale' => 'hu', 'stageId' => 3, 'status' => 1][$key] ?? null;
        }
    };
    $mapped = $adapter->mapSubmission($submission);
    if ($mapped['externalId'] !== '220' || $mapped['status'] !== 'review') {
        throw new \RuntimeException('Submission adapter did not map after direct class loading.');
    }
    $tokenClass = $prefix . 'classes\\Core\\LaunchToken';
    $claims = [
        'protocol' => 'omi-integration/1', 'context' => ['externalId' => '1'],
        'iat' => time(), 'exp' => time() + 300,
    ];
    $token = $tokenClass::issue($claims, 'test-only-secret');
    if ($tokenClass::verify($token['payload'], $token['signature'], 'test-only-secret', 1) !== $claims) {
        throw new \RuntimeException('Signed launch token helpers failed after direct class loading.');
    }
    // A later legacy-wrapper include must not redeclare any class.
    $plugin = require $root . '/index.php';
    if (!($plugin instanceof \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin)) {
        throw new \RuntimeException('Legacy wrapper did not return the plugin.');
    }
    echo "Direct and legacy class-loading checks passed for " . $entry . ".\n";
}
