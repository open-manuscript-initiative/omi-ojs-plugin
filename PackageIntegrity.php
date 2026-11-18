<?php
namespace APP\plugins\generic\studioIntegration;

/** Standalone installer guard: it must not depend on the plugin autoloader. */
final class PackageIntegrity
{
    private const REQUIRED_FILES = [
        'index.php', 'bootstrap.php', 'version.xml', 'install.xml', 'upgrade.xml',
        'PackageIntegrity.php', 'StudioIntegrationPlugin.php',
        'StudioIntegrationApiHandler.php', 'StudioIntegrationApiController.php',
        'classes/StudioIntegrationSettingsForm.php',
        'classes/Adapters/Ojs35Adapter.php',
        'classes/Core/Base64Url.php', 'classes/Core/LaunchToken.php',
        'classes/Core/ApiResponse.php', 'classes/Core/HtmlGalleyDocument.php',
        'classes/Core/PublicationArtifactDocument.php',
        'classes/Core/WorkflowFileDocument.php', 'classes/Core/WorkflowSnapshot.php',
        'classes/Core/MetadataReconciliation.php',
        'templates/settingsForm.tpl', 'js/studioIntegration.js',
        'css/studioIntegration.css',
        'locale/en/locale.po', 'locale/hu/locale.po', 'locale/de/locale.po',
    ];

    public static function assertComplete(string $root): void
    {
        $missing = [];
        foreach (self::REQUIRED_FILES as $relative) {
            $path = $root . '/' . $relative;
            clearstatcache(true, $path);
            if (!is_file($path)) $missing[] = $relative . ' (missing)';
            elseif (!is_readable($path)) $missing[] = $relative . ' (unreadable)';
            elseif (filesize($path) === 0) $missing[] = $relative . ' (empty)';
        }
        if ($missing) {
            throw new \RuntimeException(
                'OMI plugin package is incomplete. Missing, empty or unreadable: '
                . implode(', ', $missing)
                . '. Check PHP read access and plugin-directory write permissions, then reinstall the complete studioIntegration release archive.'
            );
        }
    }

    /** Invoked by the native PKP installer before it records a successful version. */
    public static function install($installer, array $attributes): bool
    {
        self::assertComplete(__DIR__);
        return true;
    }
}
