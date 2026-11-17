<?php
// OJS 3.5 may instantiate StudioIntegrationPlugin directly without index.php.
// Register an exact-case loader for this plugin's helper namespace on either path.
spl_autoload_register(static function (string $class): void {
    $prefix = 'APP\\plugins\\generic\\studioIntegration\\classes\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative)) {
        return;
    }
    $path = __DIR__ . '/classes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
