<?php
/** Controller contract test with in-memory PKP repositories. A real OJS acceptance run remains required. */
namespace Illuminate\Http {
    class JsonResponse { public function __construct(public array $data, public int $status = 200) {} }
    class Request { public function __construct(public array $data) {} public function validate($rules) { return $this->data; } }
}
namespace PKP\core { class PKPBaseController {} }
namespace PKP\security { class Role { const ROLE_ID_MANAGER = 16; const ROLE_ID_SITE_ADMIN = 1; const ROLE_ID_SUB_EDITOR = 17; const ROLE_ID_ASSISTANT = 4097; } }
namespace PKP\submissionFile { class SubmissionFile { const SUBMISSION_FILE_PROOF = 10; } }
namespace APP\submission { class Submission { const STATUS_QUEUED = 1; } }
namespace APP\core {
    class Application {
        const ASSOC_TYPE_REPRESENTATION = 521;
        public static function get() { return new self(); }
        public function getRequest() { return new \RequestContext(); }
    }
}
namespace APP\plugins\generic\studioIntegration {
    class StudioIntegrationPlugin { public function getEnabled($id) { return true; } }
}
namespace PKP\plugins { class PluginRegistry { public static function getPlugin(...$args) { return new class { public function getEnabled($id) { return \State::$reader; } }; } } }
namespace PKP\db { class DAORegistry { public static function getDAO($name) { return new class { public function getEnabledByContextId($id) { return new class {
    private bool $read = false;
    public function next() { if ($this->read) return null; $this->read = true; return new \Genre(); }
}; } }; } } }
namespace APP\facades {
    class Repo {
        public static function submission() { return new \Repository('submissions'); }
        public static function publication() { return new \Repository('publications'); }
        public static function galley() { return new \Repository('galleys'); }
        public static function submissionFile() { return new \Repository('proofs'); }
        public static function user() { return new class { public function getAccessibleWorkflowStages(...$args) { return \State::$roles; } }; }
    }
}
namespace Illuminate\Support\Facades {
    class DB {
        public static function table($name) { return new self(); }
        public function where(...$args) { return $this; }
        public function lockForUpdate() { return $this; }
        public function first() {}
        public static function transaction($callback) {
            if (\State::$race) (\State::$race)();
            $before = unserialize(serialize(\State::$rows));
            try { return $callback(); } catch (\Throwable $e) { \State::$rows = $before; throw $e; }
        }
    }
}
namespace {
    const WORKFLOW_STAGE_ID_PRODUCTION = 5;
    class HttpFailure extends \RuntimeException {}
    function abort($status, $message) { throw new HttpFailure($message, $status); }
    function response() { return new class { public function json($data, $status = 200) { return new \Illuminate\Http\JsonResponse($data, $status); } }; }
    function app() { return new class { public function get($name) { return new FileStore(); } }; }
    class Record {
        public function __construct(public array $data) {}
        public function getId() { return $this->data['id']; }
        public function getData($key, $locale = null) { $v = $this->data[$key] ?? null; return $locale ? ($v[$locale] ?? null) : $v; }
        public function getLocalizedData($key) { return $this->data[$key] ?? ''; }
    }
    class Genre extends Record {
        public function __construct() { parent::__construct(['id' => 2]); }
        public function getDependent() { return false; }
        public function getSupplementary() { return false; }
        public function getLocalizedName() { return 'Article'; }
    }
    class RequestContext {
        public function getContext() { return new class {
            public function getId() { return 1; }
            public function getSupportedSubmissionLocales() { return ['hu']; }
            public function getSupportedSubmissionMetadataLocales() { return ['hu']; }
        }; }
        public function getUser() { return new Record(['id' => 10]); }
    }
    class Repository {
        public function __construct(private string $table) {}
        public function get($id, $submissionId = null) { return State::$rows[$this->table][$id] ?? null; }
        public function getByUrlPath($path, $publication) { foreach (State::$rows['galleys'] as $g) if ($g->getData('urlPath') === $path && $g->getData('publicationId') === $publication->getId()) return $g; return null; }
        public function newDataObject($data) { return new Record($data); }
        public function add($object) { $id = count(State::$rows[$this->table]) + 1; $object->data['id'] = $id; State::$rows[$this->table][$id] = $object; return $id; }
        public function edit($object, $data) { $object->data = array_merge($object->data, $data); }
        public function getSubmissionDir(...$args) { return 'journal/submission'; }
        public function validate(...$args) { return State::$invalidFile ? ['file' => 'invalid'] : []; }
    }
    class FileStore {
        public function add($from, $to) { $id = ++State::$nextFile; State::$files[$id] = file_get_contents($from); return $id; }
        public function delete($id) { unset(State::$files[$id]); }
    }
    class State {
        public static array $rows = [], $files = [], $roles = [5 => [16]];
        public static int $nextFile = 0;
        public static bool $reader = true, $invalidFile = false;
        public static $race = null;
        public static function reset() {
            self::$rows = ['submissions' => [12 => new Record(['id' => 12, 'contextId' => 1, 'stageId' => 5, 'locale' => 'hu', 'currentPublicationId' => 13])], 'publications' => [13 => new Record(['id' => 13, 'status' => 1, 'title' => 'Minta'])], 'galleys' => [], 'proofs' => []];
            self::$files = []; self::$roles = [5 => [16]]; self::$reader = true; self::$invalidFile = false; self::$race = null;
        }
    }
    require __DIR__ . '/../classes/Core/HtmlGalleyDocument.php';
    require __DIR__ . '/../StudioIntegrationApiController.php';
    $controller = new \APP\plugins\generic\studioIntegration\StudioIntegrationApiController(new \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin());
    $body = ['action' => 'transfer', 'submissionId' => 12, 'publicationId' => 13, 'manuscriptId' => 'demo', 'locale' => 'hu', 'genreId' => 2, 'confirmed' => true, 'html' => '<!doctype html><html><head><title>Minta</title></head><body><p>Első</p></body></html>'];
    function check($condition, $message) { if (!$condition) throw new \RuntimeException($message); }
    $send = fn($data) => $controller->htmlGalley(new \Illuminate\Http\Request($data));
    State::reset();
    check($send(array_merge($body, ['action' => 'inspect']))->data['publicationId'] === 13, 'Inspection failed');
    check(!State::$files, 'Inspection wrote files');
    $first = $send($body);
    $retry = $send($body);
    check($first->data['galleyId'] === $retry->data['galleyId'] && $retry->data['unchanged'], 'Retry duplicated galley');
    check(count(State::$files) === 1 && count(State::$rows['proofs']) === 1, 'Retry leaked a file');
    $changed = $send(array_merge($body, ['html' => str_replace('Első', 'Második', $body['html'])]));
    check($changed->data['galleyId'] === $first->data['galleyId'] && count(State::$rows['galleys']) === 1, 'Update duplicated galley');
    check($changed->data['submissionFileId'] !== $first->data['submissionFileId'] && !$changed->data['published'], 'Update did not create an unpublished proof');
    check(State::$rows['publications'][13]->getData('status') === 1, 'Transfer published article');
    foreach ([
        [403, fn() => State::$roles = [5 => [65536]]],
        [404, fn() => State::$rows['submissions'][12]->data['contextId'] = 2],
        [409, fn() => State::$rows['submissions'][12]->data['stageId'] = 3],
        [409, fn() => State::$rows['publications'][13]->data['status'] = 3],
        [409, fn() => State::$rows['publications'][13]->data['status'] = 5],
        [409, fn() => State::$rows['submissions'][12]->data['currentPublicationId'] = 14],
        [409, fn() => State::$race = fn() => State::$rows['publications'][13]->data['status'] = 3],
        [422, fn() => State::$invalidFile = true],
    ] as [$status, $setup]) {
        State::reset(); $setup();
        try { $send($body); throw new \RuntimeException('Expected rejection ' . $status); }
        catch (HttpFailure $e) { check($e->getCode() === $status, 'Wrong rejection'); }
        check(!State::$files && !State::$rows['galleys'] && !State::$rows['proofs'], 'Failed transfer left artifacts');
    }
    State::reset(); State::$reader = false;
    check($send($body)->status === 409 && !State::$files, 'Disabled HTML reader accepted');
    echo "HTML transfer contract checks passed\n";
}
