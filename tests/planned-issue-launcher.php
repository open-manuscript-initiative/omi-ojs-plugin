<?php
declare(strict_types=1);
namespace PKP\plugins { class GenericPlugin { public function getSetting($id, $key) { return 'https://studio.example'; } } }
namespace PKP\form { class Form {} }
namespace PKP\core { class PKPApplication { const ROUTE_PAGE = 1; } }
namespace PKP\security { class Role { const ROLE_ID_MANAGER = 16; const ROLE_ID_SITE_ADMIN = 1; } }
namespace APP\issue {
    class Issue {
        public function __construct(private int $id, private int $journal, private bool $published = false) {}
        public function getId() { return $this->id; }
        public function getJournalId() { return $this->journal; }
        public function getPublished() { return $this->published; }
    }
}
namespace PKP\controllers\grid { class GridRow { const GRID_ACTION_POSITION_ROW_LEFT = 'left'; } }
namespace APP\controllers\grid\issues {
    class FutureIssueGridHandler {}
    class IssueGridRow extends \PKP\controllers\grid\GridRow {
        public array $actions = [];
        public function __construct(private object $issue) {}
        public function getData() { return $this->issue; }
        public function addAction($action, $position) { $this->actions[$action->id] = [$action, $position]; }
    }
}
namespace PKP\linkAction {
    class LinkAction { public function __construct(public string $id, public object $request, public string $label, public ?string $image = null, public ?string $toolTip = null) {} }
}
namespace PKP\linkAction\request {
    class RedirectAction { public function __construct(public string $url) {} }
}
namespace APP\core {
    class Application {
        public static object $request;
        public static function get() { return new self(); }
        public function getRequest() { return self::$request; }
    }
}
namespace {
    use APP\core\Application;
    use APP\issue\Issue;
    use APP\controllers\grid\issues\FutureIssueGridHandler;
    use APP\controllers\grid\issues\IssueGridRow;

    function __(string $key): string { return 'Open complete issue <test>'; }
    function check(bool $value, string $message): void { if (!$value) throw new \RuntimeException($message); }

    require dirname(__DIR__) . '/StudioIntegrationPlugin.php';
    $plugin = new class extends \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin {
        public bool $broken = false;
        public function getInstallationError(): ?array { return $this->broken ? ['code' => 'BROKEN'] : null; }
    };
    $request = new class {
        public bool $allowed = true;
        public function getContext() { return new class { public function getId() { return 1; } public function getPath() { return 'journal'; } }; }
        public function getUser() {
            return new class($this->allowed) { public function __construct(private bool $allowed) {} public function hasRole($roles, $id) { return $this->allowed && $id === 1; } };
        }
        public function getDispatcher() { return new class { public function url($request, $route, $context, $page, $op, $path, $params) {
            return '/journal/' . $page . '/' . $op . '?' . http_build_query($params);
        } }; }
    };
    Application::$request = $request;
    $template = new class($plugin) {
        public array $vars = [];
        public int $nativeFetches = 0;
        public function __construct(private object $plugin) {}
        public function getTemplateVars($key) { return $this->vars[$key] ?? null; }
        public function fetch($name, $cache, $compile) {
            $out = null;
            if ($this->plugin->issueLauncherTemplateHook('TemplateManager::fetch', [$this, $name, $cache, $compile, &$out])) return $out;
            $this->nativeFetches++;
            return '<div>Native TOC</div>';
        }
    };
    $planned = new Issue(41, 1);
    $renderRow = function ($issue, $grid) use ($template, $plugin) {
        $row = new IssueGridRow($issue);
        $template->vars = ['row' => $row, 'grid' => $grid];
        $out = null;
        check(!$plugin->issueLauncherTemplateHook('TemplateManager::fetch', [$template, 'controllers/grid/gridRow.tpl', null, null, &$out]), 'Native grid rendering must continue');
        return $row->actions;
    };
    $actions = $renderRow($planned, new FutureIssueGridHandler());
    check(isset($actions['omiOpenIssueInStudio']), 'Future issue needs native row action');
    [$action, $position] = $actions['omiOpenIssueInStudio'];
    check($action->label === 'OMI' && $action->toolTip === 'Open complete issue <test>', 'Grid action must use a compact label with the full localized tooltip');
    check($position === 'left' && $action->request->url === '/journal/omiIntegration/launch?issueId=41&redirect=1', 'Must launch exact native issue, not a submission');
    check(!$renderRow(new Issue(42, 1, true), new FutureIssueGridHandler()), 'Published issues must not launch');
    check(!$renderRow(new Issue(42, 2), new FutureIssueGridHandler()), 'Other journal must not launch');
    check(!$renderRow($planned, new \stdClass()), 'Unrelated grids must not launch');
    $request->allowed = false;
    check(!$renderRow($planned, new FutureIssueGridHandler()), 'Unauthorized user must not see action');
    $request->allowed = true;
    $plugin->broken = true;
    check(!$renderRow($planned, new FutureIssueGridHandler()), 'Incomplete plugin must not expose active issue launcher');
    $plugin->broken = false;
    $template->vars = ['issue' => $planned];
    $out = null;
    check($plugin->issueLauncherTemplateHook('TemplateManager::fetch', [$template, 'controllers/grid/issues/issueToc.tpl', null, null, &$out]), 'TOC must have launch action');
    check($template->nativeFetches === 1 && str_contains($out, 'Native TOC'), 'Native TOC must be rendered once');
    check(str_contains($out, 'issueId=41&amp;redirect=1') && str_contains($out, '&lt;test&gt;'), 'URL and label must be HTML escaped');
    $request->allowed = false;
    check(!$plugin->issueLauncherTemplateHook('TemplateManager::fetch', [$template, 'controllers/grid/issues/issueToc.tpl', null, null, &$out]), 'Unauthorized TOC must not launch');
    echo "Native future issue and TOC launcher contracts passed.\n";
}
