<?php
namespace APP\plugins\generic\studioIntegration\classes\Middleware;

use Illuminate\Http\Request;
use PKP\plugins\PluginRegistry;

final class EnsureCompleteInstallation
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $plugin = PluginRegistry::getPlugin('generic', 'studiointegrationplugin');
        $error = $plugin && method_exists($plugin, 'getInstallationError')
            ? $plugin->getInstallationError()
            : [
                'code' => 'PLUGIN_INSTALLATION_INCOMPLETE',
                'message' => 'The OJS integration plugin files are from different versions. Ask the OJS administrator to reinstall the complete release package.',
            ];
        if ($error) {
            return response()->json(['error' => $error], 503);
        }
        return $next($request);
    }
}
