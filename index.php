<?php
require_once(__DIR__ . '/classes/Core/Base64Url.php');
require_once(__DIR__ . '/classes/Core/LaunchToken.php');
require_once(__DIR__ . '/classes/Core/ApiResponse.php');
require_once(__DIR__ . '/classes/Adapters/Ojs35Adapter.php');
require_once(__DIR__ . '/classes/StudioIntegrationSettingsForm.php');
require_once(__DIR__ . '/StudioIntegrationPlugin.php');
return new \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin();
