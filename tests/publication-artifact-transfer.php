<?php
/** Controller contract test with in-memory PKP repositories. Real OJS production acceptance remains required. */
namespace Illuminate\Http {
    class JsonResponse { public function __construct(public array $data, public int $status = 200) {} public function header($key, $value) { return $this; } }
    class Request { public function __construct(public array $data) {} public function validate($rules) { return $this->data; } }
}
namespace PKP\core { class PKPBaseController {} }
namespace PKP\security { class Role { const ROLE_ID_MANAGER = 16; const ROLE_ID_SITE_ADMIN = 1; const ROLE_ID_SUB_EDITOR = 17; const ROLE_ID_ASSISTANT = 4097; } }
namespace PKP\submissionFile { class SubmissionFile { const SUBMISSION_FILE_PROOF = 10; } }
namespace APP\submission { class Submission { const STATUS_QUEUED = 1; const STATUS_SCHEDULED = 5; } }
namespace APP\core {
    class Application {
        const ASSOC_TYPE_REPRESENTATION = 521;
        public static function get() { return new self(); }
        public function getRequest() { return new \RequestContext(); }
    }
}
namespace APP\plugins\generic\studioIntegration {
    class StudioIntegrationPlugin { public function getEnabled($id) { return true; } public function getInstallationId(int $contextId, $request): string { return 'test-installation'; } }
}
namespace APP\plugins\generic\studioIntegration\classes\Adapters {
    class Ojs35Adapter {
        public function reconciliationKeywords($publication, $locale) { return \State::$keywords; }
        public function mapContributors($submission) { return []; }
    }
}
namespace PKP\observers\events { class MetadataChanged { public function __construct(public $submission) {} } }
namespace PKP\plugins {
    class PluginRegistry {
        public static function getPlugin(...$args) {
            return new class {
                public function getEnabled($id) { return \State::$reader; }
            };
        }
    }
}
namespace PKP\db {
    class DAORegistry {
        public static function getDAO($name) {
            return new class {
                public function getEnabledByContextId($id) {
                    return new class {
                        private bool $read = false;
                        public function next() {
                            if ($this->read) return null;
                            $this->read = true;
                            return new \Genre();
                        }
                    };
                }
            };
        }
    }
}
namespace APP\facades {
    class Repo {
        public static function submission() { return new \Repository('submissions'); }
        public static function publication() { return new \Repository('publications'); }
        public static function issue() { return new \Repository('issues'); }
        public static function galley() { return new \Repository('galleys'); }
        public static function submissionFile() { return new \Repository('proofs'); }
        public static function user() {
            return new class {
                public function getAccessibleWorkflowStages(...$args) { return \State::$roles; }
            };
        }
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
            try { return $callback(); }
            catch (\Throwable $e) { \State::$rows = $before; throw $e; }
        }
    }
}
namespace {
    require_once __DIR__ . '/../classes/Core/WorkflowSnapshot.php';
    require_once __DIR__ . '/../classes/Core/MetadataReconciliation.php';
    function event($event) { State::$metadataEvents++; }
    const WORKFLOW_STAGE_ID_PRODUCTION = 5;

    class HttpFailure extends \RuntimeException {}
    function abort($status, $message) { throw new HttpFailure($message, $status); }
    function response() {
        return new class {
            public function json($data, $status = 200) {
                return new \Illuminate\Http\JsonResponse($data, $status);
            }
        };
    }
    function app() {
        return new class {
            public function get($name) { return new FileStore(); }
        };
    }

    class Record {
        public function __construct(public array $data) {}
        public function getId() { return $this->data['id']; }
        public function getData($key, $locale = null) {
            $value = $this->data[$key] ?? null;
            return $locale ? ($value[$locale] ?? null) : $value;
        }
        public function getLocalizedData($key) { return $this->data[$key] ?? ''; }
        public function getJournalId() { return $this->getData('journalId'); }
        public function getPublished() { return $this->getData('published'); }
        public function hasRole($roles, $contextId) { return $contextId === 1 && (bool)array_intersect($roles, State::$roles[5] ?? []); }
    }
    class Genre extends Record {
        public function __construct() { parent::__construct(['id' => 2]); }
        public function getDependent() { return false; }
        public function getSupplementary() { return false; }
        public function getLocalizedName() { return 'Article'; }
    }
    class RequestContext {
        public function getContext() {
            return new class {
                public function getId() { return 1; }
                public function getSupportedSubmissionLocales() { return ['hu']; }
                public function getSupportedSubmissionMetadataLocales() { return ['hu']; }
            };
        }
        public function getUser() { return new Record(['id' => 10]); }
    }
    class Repository {
        public function __construct(private string $table) {}
        public function get($id, $submissionId = null) { return State::$rows[$this->table][$id] ?? null; }
        public function getByUrlPath($path, $publication) {
            foreach (State::$rows['galleys'] as $galley) {
                if (
                    $galley->getData('urlPath') === $path &&
                    $galley->getData('publicationId') === $publication->getId()
                ) return $galley;
            }
            return null;
        }
        public function canEditPublication($submissionId, $userId) { return \State::$canEdit && $submissionId === 12 && $userId === 10; }
        public function newDataObject($data) { return new Record($data); }
        public function add($object) {
            $id = count(State::$rows[$this->table]) + 1;
            $object->data['id'] = $id;
            State::$rows[$this->table][$id] = $object;
            return $id;
        }
        public function edit($object, $data) { $object->data = array_merge($object->data, $data); if (isset($data['keywords'])) State::$keywords = $data['keywords']; }
        public function getSubmissionDir(...$args) { return 'journal/submission'; }
        public function validate(...$args) { return State::$invalidFile ? ['file' => 'invalid'] : []; }
    }
    class FileStore {
        public function add($from, $to) {
            $id = ++State::$nextFile;
            State::$files[$id] = ['bytes' => file_get_contents($from), 'path' => $to];
            return $id;
        }
        public function delete($id) { unset(State::$files[$id]); }
    }
    class State {
        public static array $rows = [], $files = [], $roles = [5 => [16]];
        public static int $nextFile = 0;
        public static bool $reader = true, $invalidFile = false;
        public static $race = null;

        public static bool $canEdit = true;
        public static int $metadataEvents = 0;
        public static array $keywords = ['hu' => ['kulcs'], 'en' => ['key']];
        public static function reset() {
            self::$canEdit = true; self::$metadataEvents = 0; self::$keywords = ['hu' => ['kulcs'], 'en' => ['key']];
            self::$rows = [
                'submissions' => [
                    12 => new Record([
                        'id' => 12,
                        'contextId' => 1,
                        'stageId' => 5,
                        'locale' => 'hu',
                        'currentPublicationId' => 13,
                    ]),
                ],
                'publications' => [
                    13 => new Record([
                        'id' => 13,
                        'status' => 1,
                        'submissionId' => 12,
                        'title' => 'Minta',
                    ]),
                ],
                'issues' => [41 => new Record(['id' => 41, 'journalId' => 1, 'published' => false])],
                'galleys' => [],
                'proofs' => [],
            ];
            self::$files = [];
            self::$roles = [5 => [16]];
            self::$reader = true;
            self::$invalidFile = false;
            self::$race = null;
            self::$nextFile = 0;
        }
    }

    function canonical(mixed $value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (array_is_list($value)) {
            return '[' . implode(',', array_map('canonical', $value)) . ']';
        }
        ksort($value, SORT_STRING);
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = json_encode((string)$key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . ':' . canonical($item);
        }
        return '{' . implode(',', $parts) . '}';
    }

    function buildManifest(string $format, string $mediaType, string $fileName, string $bytes): array
    {
        $identity = [
            'model' => 'omi-publication-build',
            'version' => '0.1.0',
            'manuscript' => [
                'id' => 'demo',
                'revisionId' => 'rev-1',
                'stateDigest' => [
                    'algorithm' => 'sha256',
                    'value' => str_repeat('1', 64),
                    'canonicalization' => 'omi-manuscript-state-json-v1',
                ],
            ],
            'profile' => [
                'id' => 'journal',
                'version' => '1',
                'digest' => [
                    'algorithm' => 'sha256',
                    'value' => str_repeat('2', 64),
                    'canonicalization' => 'omi-publication-profile-json-v1',
                ],
            ],
            'output' => [
                'format' => $format,
                'mediaType' => $mediaType,
                'fileName' => $fileName,
                'byteLength' => strlen($bytes),
                'digest' => [
                    'algorithm' => 'sha256',
                    'value' => hash('sha256', $bytes),
                ],
            ],
            ...(str_starts_with($format, 'pdf') ? [
                'rendererInput' => [
                    'mediaType' => 'text/html;charset=utf-8',
                    'byteLength' => 31,
                    'digest' => [
                        'algorithm' => 'sha256',
                        'value' => hash('sha256', '<html><body>PDF</body></html>'),
                    ],
                ],
            ] : []),
            'generator' => [
                'application' => 'open-manuscript-studio',
                'applicationVersion' => '0.2.0-beta.1',
                'renderer' => str_starts_with($format, 'pdf') ? 'vivliostyle-cli' : 'omi-renderer',
                'rendererVersion' => str_starts_with($format, 'pdf') ? '11.0.4' : '1',
            ],
        ];

        return array_merge(
            [
                'model' => $identity['model'],
                'version' => $identity['version'],
                'id' => 'urn:omi:publication-build:sha256:' . hash('sha256', canonical($identity)),
                'createdAt' => '2026-09-18T12:00:00.000Z',
            ],
            array_slice($identity, 2, null, true)
        );
    }

    function bodyFor(string $format, string $bytes, string $fileName, string $mediaType): array
    {
        return [
            'action' => 'transfer',
            'submissionId' => 12,
            'publicationId' => 13,
            'manuscriptId' => 'demo',
            'locale' => 'hu',
            'genreId' => 2,
            'format' => $format,
            'mediaType' => $mediaType,
            'fileName' => $fileName,
            'artifactBase64' => base64_encode($bytes),
            'build' => buildManifest($format, $mediaType, $fileName, $bytes),
            'confirmed' => true,
        ];
    }

    function check($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
    }

    require __DIR__ . '/../classes/Core/HtmlGalleyDocument.php';
    require __DIR__ . '/../classes/Core/PublicationArtifactDocument.php';
    require __DIR__ . '/../StudioIntegrationApiController.php';

    $controller = new \APP\plugins\generic\studioIntegration\StudioIntegrationApiController(
        new \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin()
    );
    $send = fn(array $data) => $controller->publicationArtifact(new \Illuminate\Http\Request($data));

    $html = '<!doctype html><html><head><title>Minta</title></head><body><p>Első</p></body></html>';
    $jats = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<!DOCTYPE article SYSTEM "https://jats.nlm.nih.gov/articleauthoring/1.4/JATS-articleauthoring1-4-mathml3.dtd">' . "\n"
        . '<article dtd-version="1.4"><front><article-meta><title-group><article-title>Minta</article-title></title-group></article-meta></front><body><p>Szöveg</p></body></article>';
    $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

    State::reset();
    $inspect = $send(['action' => 'inspect', 'submissionId' => 12, 'manuscriptId' => 'demo']);
    check($inspect->data['protocol'] === 'omi-publication-artifact/1', 'Inspection protocol mismatch');
    check($inspect->data['publicationId'] === 13, 'Inspection did not bind current publication');
    check($inspect->data['provenance']['required'] === true, 'Inspection did not require provenance');
    check(count($inspect->data['formats']) === 5, 'Inspection did not advertise publication formats');
    check(!State::$files, 'Inspection wrote files');
    $workflow = $controller->workflowStatus(new \Illuminate\Http\Request(['submissionId' => 12]));
    check($workflow->data['authority'] === 'OJS', 'Workflow must identify OJS authority');
    check($workflow->data['stateToken'] === $inspect->data['workflowToken'], 'Inspection and workflow must agree');

    $htmlBody = bodyFor('html', $html, 'article.html', 'text/html;charset=utf-8');
    $htmlBody['expectedWorkflowToken'] = $inspect->data['workflowToken'];
    $first = $send($htmlBody);
    check($first->data['provenanceVerified'] === true, 'HTML provenance was not verified');
    check($first->data['published'] === false, 'Transfer published HTML');
    $retry = $send($htmlBody);
    check(
        $first->data['galleyId'] === $retry->data['galleyId'] &&
        $retry->data['unchanged'] === true,
        'Identical HTML retry duplicated the galley'
    );
    check(count(State::$files) === 1 && count(State::$rows['proofs']) === 1, 'HTML retry leaked files');

    $changedHtml = str_replace('Első', 'Második', $html);
    $changed = $send(bodyFor('html', $changedHtml, 'article.html', 'text/html;charset=utf-8'));
    check($changed->data['galleyId'] === $first->data['galleyId'], 'Changed HTML duplicated galley');
    check($changed->data['submissionFileId'] !== $first->data['submissionFileId'], 'Changed HTML did not create a new proof');

    $jatsResult = $send(bodyFor('jats', $jats, 'article.jats.xml', 'application/xml'));
    $pdfResult = $send(bodyFor('pdf-print', $pdf, 'article.pdf', 'application/pdf'));
    check($jatsResult->data['format'] === 'jats', 'JATS transfer failed');
    check($pdfResult->data['format'] === 'pdf-print', 'PDF transfer failed');
    check(count(State::$rows['galleys']) === 3, 'Formats did not receive independent galley identities');

    State::reset();
    $bad = bodyFor('jats', $jats, 'article.jats.xml', 'application/xml');
    $bad['build']['output']['digest']['value'] = str_repeat('0', 64);
    $response = $send($bad);
    check($response->status === 422, 'Tampered provenance was accepted');
    check(!State::$files && !State::$rows['galleys'] && !State::$rows['proofs'], 'Rejected provenance left OJS artifacts');

    State::reset();
    State::$reader = false;
    $response = $send($htmlBody);
    check($response->status === 409, 'HTML transfer succeeded without HTML reader');
    $jatsWithoutReader = $send(bodyFor('jats', $jats, 'article.jats.xml', 'application/xml'));
    check($jatsWithoutReader->status === 200, 'JATS incorrectly depended on HTML reader');

    foreach ([
        [403, fn() => State::$roles = [5 => [65536]]],
        [404, fn() => State::$rows['submissions'][12]->data['contextId'] = 2],
        [409, fn() => State::$rows['submissions'][12]->data['stageId'] = 3],
        [409, fn() => State::$rows['publications'][13]->data['status'] = 3],
        [409, fn() => State::$rows['submissions'][12]->data['currentPublicationId'] = 14],
        [409, fn() => State::$race = fn() => State::$rows['publications'][13]->data['status'] = 3],
        [409, fn() => State::$rows['publications'][13]->data['pages'] = '9-17'],
        [409, fn() => State::$race = fn() => State::$rows['publications'][13]->data['pages'] = '9-17'],
        [422, fn() => State::$invalidFile = true],
    ] as [$status, $setup]) {
        State::reset();
        $request = bodyFor('jats', $jats, 'article.jats.xml', 'application/xml');
        $request['expectedWorkflowToken'] = \APP\plugins\generic\studioIntegration\classes\Core\WorkflowSnapshot::create(State::$rows['submissions'][12], State::$rows['publications'][13])['stateToken'];
        $setup();
        try {
            $result = $send($request);
            if ($result->status === $status) continue;
            throw new \RuntimeException('Expected rejection ' . $status);
        } catch (HttpFailure $failure) {
            check($failure->getCode() === $status, 'Wrong rejection status');
        }
        check(!State::$files && !State::$rows['galleys'] && !State::$rows['proofs'], 'Failed transfer left artifacts');
    }

    State::reset();
    State::$roles = [5 => [65536]];
    check($controller->workflowStatus(new \Illuminate\Http\Request(['submissionId' => 12]))->status === 403, 'Reviewer must not read editorial workflow');

    State::reset();
    State::$rows['publications'][13]->data['title'] = ['hu' => 'Régi', 'en' => 'Keep'];
    $metadataInspect = fn() => $controller->metadata(new \Illuminate\Http\Request(['action' => 'inspect', 'submissionId' => 12]));
    $proposal = fn($token) => ['action' => 'apply', 'submissionId' => 12, 'publicationId' => 13, 'locale' => 'hu', 'expectedStateToken' => $token, 'fields' => ['title' => 'Új', 'keywords' => ['új']], 'confirmed' => true];
    $metadata = $metadataInspect()->data['snapshot'];
    $applied = $controller->metadata(new \Illuminate\Http\Request($proposal($metadata['stateToken'])));
    check($applied->data['snapshot']['metadata']['title']['hu'] === 'Új', 'Metadata title was not applied');
    check(State::$rows['publications'][13]->data['title']['en'] === 'Keep', 'Metadata proposal overwrote another locale');
    check(State::$keywords['en'] === ['key'], 'Keyword proposal overwrote another locale');
    check(State::$metadataEvents === 1, 'Native metadata-change event was not emitted');
    check($applied->data['receipt']['published'] === false, 'Metadata edit must not publish');
    foreach ([
        [403, function (&$body) { State::$canEdit = false; }],
        [403, function (&$body) { State::$roles = [5 => [65536]]; }],
        [404, function (&$body) { State::$rows['submissions'][12]->data['contextId'] = 2; }],
        [409, function (&$body) { $body['publicationId'] = 99; }],
        [409, function (&$body) { State::$race = fn() => State::$rows['publications'][13]->data['title'] = ['hu' => 'Concurrent']; }],
        [422, function (&$body) { $body['fields'] = ['doi' => 'forged']; }],
        [422, function (&$body) { $body['locale'] = 'de'; }],
        [422, function (&$body) { State::$invalidFile = true; }],
    ] as [$expected, $setup]) {
        State::reset();
        $body = $proposal($metadataInspect()->data['snapshot']['stateToken']);
        $setup($body);
        try { $controller->metadata(new \Illuminate\Http\Request($body)); throw new \RuntimeException('Metadata rejection missing'); }
        catch (HttpFailure $error) { check($error->getCode() === $expected, 'Wrong metadata rejection status'); }
        check(State::$metadataEvents === 0, 'Rejected proposal emitted a metadata event');
    }

    // Scheduled production articles require an explicit matching issue target.
    $scheduled = function () {
        State::reset();
        State::$rows['submissions'][12]->data['status'] = 5;
        State::$rows['publications'][13]->data['status'] = 5;
        State::$rows['publications'][13]->data['issueId'] = 41;
    };
    $issueInspect = ['action' => 'inspect', 'submissionId' => 12, 'publicationId' => 13,
        'manuscriptId' => 'demo', 'expectedIssueId' => 41, 'expectedInstallationId' => 'test-installation'];
    $scheduled();
    $target = $send($issueInspect);
    check($target->status === 200 && $target->data['issueId'] === 41 &&
        $target->data['installationId'] === 'test-installation' && $target->data['contextId'] === 1,
        'Scheduled issue inspection failed');
    $issueBody = array_merge(bodyFor('jats', $jats, 'article.jats.xml', 'application/xml'), [
        'expectedIssueId' => 41, 'expectedInstallationId' => 'test-installation',
        'expectedWorkflowToken' => $target->data['workflowToken'],
    ]);
    $result = $send($issueBody);
    check($result->status === 200 && $result->data['issueId'] === 41 && $result->data['published'] === false,
        'Scheduled issue transfer failed');
    check(State::$rows['publications'][13]->getData('status') === 5, 'Scheduled status changed');
    foreach ([
        [403, function (&$body) { $body['expectedInstallationId'] = 'wrong'; }],
        [403, function (&$body) { State::$roles = [5 => [17]]; }],
        [409, function (&$body) { State::$rows['issues'][41]->data['published'] = true; }],
        [409, function (&$body) { State::$rows['publications'][13]->data['issueId'] = 42; }],
        [409, function (&$body) { $body['publicationId'] = 99; }],
        [422, function (&$body) { unset($body['expectedWorkflowToken']); }],
        [409, function (&$body) { State::$race = fn() => State::$rows['issues'][41]->data['published'] = true; }],
        [409, function (&$body) { unset($body['expectedIssueId'], $body['expectedInstallationId']); }],
    ] as [$expected, $setup]) {
        $scheduled();
        $body = $issueBody;
        $setup($body);
        try {
            $result = $send($body);
            check($result->status === $expected, 'Wrong issue transfer rejection status');
        } catch (HttpFailure $error) {
            check($error->getCode() === $expected, 'Wrong issue transfer rejection status');
        }
        check(!State::$files && !State::$rows['proofs'] && !State::$rows['galleys'], 'Rejected issue transfer left artifacts');
    }

    $inspectPages = ['action' => 'inspect', 'installationId' => 'test-installation',
        'contextId' => 1, 'issueId' => 41, 'submissionId' => 12, 'publicationId' => 13];
    $sendPages = fn(array $data) => $controller->issuePageRange(new \Illuminate\Http\Request($data));
    $scheduled();
    $initial = $sendPages($inspectPages)->data['snapshot'];
    check($initial['protocol'] === 'omi-issue-page-range/1' && $initial['pages'] === null, 'Invalid page inspection');
    $pageBody = array_merge($inspectPages, ['action' => 'apply', 'firstPrintedPage' => 101,
        'lastPrintedPage' => 118, 'expectedWorkflowToken' => $initial['workflowToken'], 'confirmed' => true]);
    $result = $sendPages($pageBody)->data;
    check($result['receipt']['pages'] === '101-118' && $result['receipt']['previousPages'] === null, 'Printed pages not written');
    check($result['snapshot']['workflowToken'] !== $initial['workflowToken'], 'Page update did not change workflow token');
    check(State::$rows['publications'][13]->getData('status') === 5 && State::$rows['publications'][13]->getData('issueId') === 41, 'Page update changed publication state');
    check(State::$metadataEvents === 1, 'Metadata event not emitted');
    check(!State::$files && !State::$rows['galleys'], 'Page write unexpectedly created artifacts');
    $retry = $pageBody;
    $retry['expectedWorkflowToken'] = $result['snapshot']['workflowToken'];
    check($sendPages($retry)->data['receipt']['unchanged'] === true && State::$metadataEvents === 1, 'Identical page range should not write twice');
    $retry['firstPrintedPage'] = $retry['lastPrintedPage'] = 119;
    check($sendPages($retry)->data['receipt']['pages'] === '119', 'Single printed page not normalized');
    foreach ([
        [403, function (&$body) { $body['installationId'] = 'other'; }],
        [403, function (&$body) { $body['contextId'] = 2; }],
        [403, function (&$body) { State::$roles = [5 => [17]]; }],
        [403, function (&$body) { State::$canEdit = false; }],
        [404, function (&$body) { State::$rows['issues'][41]->data['journalId'] = 2; }],
        [409, function (&$body) { State::$rows['issues'][41]->data['published'] = true; }],
        [409, function (&$body) { $body['publicationId'] = 99; }],
        [409, function (&$body) { State::$rows['publications'][13]->data['issueId'] = 42; }],
        [409, function (&$body) { State::$race = fn() => State::$rows['publications'][13]->data['pages'] = '2-8'; }],
        [409, function (&$body) { State::$race = fn() => State::$rows['issues'][41]->data['published'] = true; }],
        [422, function (&$body) { $body['lastPrintedPage'] = 100; }],
        [422, function (&$body) { State::$invalidFile = true; }],
    ] as [$expected, $setup]) {
        $scheduled();
        $body = $pageBody;
        $body['expectedWorkflowToken'] = $sendPages($inspectPages)->data['snapshot']['workflowToken'];
        $setup($body);
        try {
            $result = $sendPages($body);
            check($result->status === $expected, 'Wrong page range rejection status');
        } catch (HttpFailure $error) {
            check($error->getCode() === $expected, 'Wrong page range rejection status');
        }
        check(State::$rows['publications'][13]->getData('pages') !== '101-118' && State::$metadataEvents === 0, 'Rejected page write changed metadata');
    }

    echo "Publication artifact transfer contract checks passed\n";
}
