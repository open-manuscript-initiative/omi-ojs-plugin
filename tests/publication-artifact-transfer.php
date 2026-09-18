<?php
/** Controller contract test with in-memory PKP repositories. Real OJS production acceptance remains required. */
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
        public function newDataObject($data) { return new Record($data); }
        public function add($object) {
            $id = count(State::$rows[$this->table]) + 1;
            $object->data['id'] = $id;
            State::$rows[$this->table][$id] = $object;
            return $id;
        }
        public function edit($object, $data) { $object->data = array_merge($object->data, $data); }
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

        public static function reset() {
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
                        'title' => 'Minta',
                    ]),
                ],
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
    check(count($inspect->data['formats']) === 4, 'Inspection did not advertise publication formats');
    check(!State::$files, 'Inspection wrote files');

    $htmlBody = bodyFor('html', $html, 'article.html', 'text/html;charset=utf-8');
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
        [422, fn() => State::$invalidFile = true],
    ] as [$status, $setup]) {
        State::reset();
        $setup();
        try {
            $result = $send(bodyFor('jats', $jats, 'article.jats.xml', 'application/xml'));
            if ($result->status === $status) continue;
            throw new \RuntimeException('Expected rejection ' . $status);
        } catch (HttpFailure $failure) {
            check($failure->getCode() === $status, 'Wrong rejection status');
        }
        check(!State::$files && !State::$rows['galleys'] && !State::$rows['proofs'], 'Failed transfer left artifacts');
    }

    echo "Publication artifact transfer contract checks passed\n";
}
