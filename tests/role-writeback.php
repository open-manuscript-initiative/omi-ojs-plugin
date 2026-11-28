<?php
/** Runs the actual controller against in-memory PKP repositories. */
namespace Illuminate\Http {
    class JsonResponse {
        public function __construct(public array $data, public int $status = 200) {}
        public function getStatusCode() { return $this->status; }
        public function getData($assoc = false) { return $this->data; }
    }
    class Request {
        public function __construct(public array $data, public string $operation = 'assignment-result') {}
        public function input($key, $default = null) { return $this->data[$key] ?? $default; }
        public function getContent() { return json_encode($this->data); }
        public function getMethod() { return 'POST'; }
        public function getPathInfo() { return '/ojs/index.php/e/api/v1/omi-integration/' . $this->operation; }
        public function header($key, $default = '') {
            $time = (string)time();
            return match ($key) {
                'X-OMI-Installation' => 'test-installation', 'X-OMI-Timestamp' => $time,
                'X-OMI-Signature' => rtrim(strtr(base64_encode(hash_hmac('sha256', $time . "\nPOST\n" . $this->getPathInfo() . "\n" . hash('sha256', $this->getContent()), 'test-secret', true)), '+/', '-_'), '='),
                default => $default,
            };
        }
    }
}
namespace Illuminate\Http\Exceptions {
    class HttpResponseException extends \RuntimeException {
        public function __construct(private \Illuminate\Http\JsonResponse $response) { parent::__construct('HTTP response', $response->getStatusCode()); }
        public function getResponse() { return $this->response; }
    }
}
namespace PKP\core { class PKPBaseController {} class Core { public static function getCurrentDate() { return '2026-11-28 04:00:00'; } } }
namespace PKP\security { class Role { const ROLE_ID_MANAGER=16, ROLE_ID_SUB_EDITOR=17, ROLE_ID_ASSISTANT=4097, ROLE_ID_AUTHOR=65536, ROLE_ID_REVIEWER=4096; } }
namespace PKP\submissionFile { class SubmissionFile { const SUBMISSION_FILE_SUBMISSION=2, SUBMISSION_FILE_REVIEW_ATTACHMENT=5, SUBMISSION_FILE_COPYEDIT=9, SUBMISSION_FILE_PRODUCTION_READY=11, SUBMISSION_FILE_REVIEW_REVISION=15; } }
namespace PKP\submission { class SubmissionComment { const COMMENT_TYPE_PEER_REVIEW=1, COMMENT_TYPE_COPYEDIT=3; } }
namespace PKP\submission\reviewAssignment {
    class ReviewAssignment extends \Record {
        public static function getReviewerRecommendationOptions() { return []; }
        const SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS=2, REVIEW_ASSIGNMENT_STATUS_DECLINED=5, REVIEW_ASSIGNMENT_STATUS_COMPLETE=6, REVIEW_ASSIGNMENT_STATUS_THANKED=7, REVIEW_ASSIGNMENT_STATUS_CANCELLED=8;
    }
}
namespace APP\submission { class Submission { const STATUS_QUEUED=1; } }
namespace APP\plugins\generic\studioIntegration\classes\Adapters { class Ojs35Adapter { public function reconciliationKeywords($publication,$locale) { return $publication->getData('keywords') ?? []; } } }
namespace APP\core { class Application {
    const ASSOC_TYPE_REVIEW_ASSIGNMENT=1, ASSOC_TYPE_REVIEW_ROUND=2, ASSOC_TYPE_SUBMISSION=3;
    public static function get() { return new self(); }
    public static function getSectionIdPropName() { return "sectionId"; }
    public function getRequest() { return new class { public function getContext() { return new \Record(['id'=>1]); } }; }
} }
namespace APP\plugins\generic\studioIntegration { class StudioIntegrationPlugin {
    public function getInstallationId(...$args) { return 'test-installation'; }
    public function getSetting($context, $key) { return $key === 'sharedSecret' ? 'test-secret' : (\State::$settings[$key] ?? null); }
    public function updateSetting($context, $key, $value, $type) { \State::$settings[$key] = $value; }
} }
namespace PKP\stageAssignment { class StageAssignment {
    public static function withSubmissionIds($ids) { return new self(); }
    public function __call($method,$args) { return $this; }
    public function all() { return [30]; }
} }
namespace APP\facades { class Repo {
    public static function query() { return new class { public function addQuery($submission,$stage,$title,$body,$user,$participants,$context,$sendEmail) { \State::$comments[]=['viewable'=>in_array(30,$participants,true),'participants'=>$participants,'body'=>$body]; } }; }
    public static function publication() { return new \Repository('publication'); }
    public static function galley() { return new class { public function getCollector() { return $this; } public function filterByPublicationIds($ids) { return $this; } public function getMany() { return []; } }; }
    public static function submission() { return new \Repository('submission'); }
    public static function reviewAssignment() { return new \Repository('review'); }
    public static function submissionFile() { return new \Repository('file'); }
    public static function user() { return new \Repository('user'); }
    public static function userGroup() { return new class { public function getArrayIdByRoleId(...$args) { return [1]; } }; }
} }
namespace PKP\db { class DAORegistry { public static function getDAO($name) { return new \Dao($name); } } }
namespace Illuminate\Support\Facades { class DB {
    public static function table($name) { return new self(); }
    public function where(...$args) { return $this; }
    public function lockForUpdate() { return $this; }
    public function first() { return null; }
    public static function transaction($fn) {
        $before = serialize([\State::$files, \State::$comments, \State::$settings, \State::$review, \State::$publication]);
        try { return $fn(); } catch (\Throwable $error) {
            [\State::$files, \State::$comments, \State::$settings, \State::$review, \State::$publication] = unserialize($before); throw $error;
        }
    }
} }
namespace {
    const WORKFLOW_STAGE_ID_SUBMISSION=1, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW=3, WORKFLOW_STAGE_ID_EDITING=4, WORKFLOW_STAGE_ID_PRODUCTION=5;
    class Record {
        public function __construct(public array $data=[]) {}
        public function getData($key) { return $this->data[$key] ?? null; }
        public function __call($method, $args) {
            $key = lcfirst(substr($method, 3));
            if (str_starts_with($method, 'set')) { $this->data[$key] = $args[0]; return; }
            return $this->data[$key] ?? null;
        }
        public function getSupportedSubmissionMetadataLocales() { return ['hu', 'en', 'de']; }
        public function getCurrentPublication() { return State::$publication; }
    }
    class State {
        public static array $files=[], $comments=[], $settings=[], $storage=[];
        public static $review, $publication;
        public static bool $editor=true, $failSecond=false;
        public static int $stage=4;
        public static function reset() { self::$files=self::$comments=self::$settings=self::$storage=[]; self::$review=null; self::$editor=true; self::$failSecond=false; self::$stage=4; self::$publication=new Record(['id'=>8,'status'=>1,'title'=>['hu'=>'Magyar cím','de'=>'Deutscher Titel'],'abstract'=>['hu'=>'Magyar összefoglaló','de'=>'Zusammenfassung'],'keywords'=>['hu'=>['magyar'],'de'=>['deutsch']]]); }
    }
    class Repository {
        public function __construct(public string $kind) {}
        public function get($id, $parent=null) {
            return match ($this->kind) {
                'submission' => (int)$id===217 && ($parent===null || (int)$parent===1) ? new Record(['id'=>217,'stageId'=>State::$stage,'locale'=>'hu']) : null,
                'publication' => State::$publication,
                'review' => (int)$id===31 && ($parent===null || (int)$parent===217) ? State::$review : null,
                'file' => isset(State::$files[$id]) ? new Record(State::$files[$id]) : null,
                'user' => in_array((int)$id,[10,20],true) ? new Record(['id'=>(int)$id,'email'=>'participant@example.org']) : null,
            };
        }
        public function canEditPublication($submissionId,$editorId) { return State::$editor; }
        public function getByEmail($email) { return $email==='participant@example.org' ? $this->get(20) : null; }
        public function getAccessibleWorkflowStages($user, ...$args) { return $user===10 && State::$editor ? [State::$stage=>[16]] : []; }
        public function getCollector() { return new class($this->kind) {
            public function __construct(public string $kind) {}
            public function __call($name, $args) { return $this; }
            public function getMany() { return new class($this->kind) {
                public function __construct(public string $kind) {}
                public function first($callback=null) { return $this->kind==='user' ? new Record(['id'=>20]) : State::$review; }
            }; }
        }; }
        public function validate(...$args) {
            if ($this->kind !== 'file') return [];
            $params = $args[1];
            if (array_key_exists('genreId', $params) && !is_int($params['genreId'])) return ['genreId'=>['integer']];
            if (($params['assocType'] ?? null) === \APP\core\Application::ASSOC_TYPE_SUBMISSION) return ['assocType'=>['invalid']];
            return State::$failSecond && count(State::$files)===1 ? ['invalid'=>true] : [];
        }
        public function newDataObject($params) { return $this->kind==='review' ? new \PKP\submission\reviewAssignment\ReviewAssignment($params) : new Record($params); }
        public function add($record) {
            if ($this->kind==='review') { $record->data['id']=31; $record->data['status']=1; State::$review=$record; return 31; }
            $id=count(State::$files)+1; State::$files[$id]=$record->data+['id'=>$id]; return $id;
        }
        public function edit($record, $params) { $record->data=array_merge($record->data,$params); }
        public function getSubmissionDir(...$args) { return 'test'; }
    }
    class Dao {
        public function __construct(public string $name) {}
        public function getLastReviewRoundBySubmissionId(...$args) { return $this->getById(5); }
        public function getById($id) { return new Record(['id'=>5,'submissionId'=>217,'stageId'=>3,'round'=>2]); }
        public function getEnabledByContextId(...$args) { return new class { public bool $read=false; public function next() { if($this->read)return null; $this->read=true; return new Record(['id'=>1,'dependent'=>false]); } }; }
        public function newDataObject() { return new Record(); }
        public function getReviewerCommentsByReviewerId(...$args) { return new class { public function next() { return null; } }; }
        public function insertObject($record) { State::$comments[]=$record->data; }
    }
    function response() { return new class { public function json($data, $status=200) { return new \Illuminate\Http\JsonResponse($data,$status); } }; }
    function abort($status,$message) { throw new \LogicException('PKPContainer does not implement abort()'); }
    function app() { return new class { public function get($name) { return new class {
        public function add($tmp,$path) { $id=count(State::$storage)+1; State::$storage[$id]=file_get_contents($tmp); return $id; }
        public function get($id) { return (object)['mimetype'=>'application/octet-stream']; }
        public function delete($id) { unset(State::$storage[$id]); }
    }; } }; }
    require_once __DIR__.'/../StudioIntegrationApiController.php';
    $controller=new \APP\plugins\generic\studioIntegration\StudioIntegrationApiController(new \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin());
    function check($condition,$message) { if(!$condition) throw new \RuntimeException($message); }
    function bindTask($controller,$type,$fullText=true,$target='en') {
        $result=$controller->workflowAssignment(new \Illuminate\Http\Request(['assignmentId'=>'11111111-1111-4111-8111-111111111111','submissionExternalId'=>'217','editorExternalId'=>'10','reviewerEmail'=>'participant@example.org','assignmentType'=>$type,'sourceLanguage'=>'hu','targetLanguage'=>$target,...($type==='TRANSLATION' && $fullText ? ['publicationLanguages'=>['hu',$target]] : [])],'workflow-assignment'));
        check($result->status===200,'Binding failed: '.json_encode($result->data)); return $result->data;
    }
    function resultInput($binding,$type) {
        $snapshot=['protocol'=>'omi-review-revision/1','assignmentType'=>$type,'anonymityMode'=>$type==='SCIENTIFIC_REVIEW'?'double_blind':'open','manuscript'=>['authorIdentity'=>$type==='SCIENTIFIC_REVIEW'?'hidden':'visible','blocks'=>[['type'=>'paragraph','text'=>'Corrected text']],'annotations'=>[]]];
        $bound=json_decode(base64_decode(strtr(explode('.', $binding['assignmentToken'])[0], '-_', '+/')),true);
        $translated=['sourceLanguage'=>'hu','targetLanguage'=>$bound['targetLanguage'] ?? 'en','title'=>'Translated title','abstract'=>'Translated abstract','keywords'=>['translated'],'documentCompleted'=>true];
        if ($type==='TRANSLATION') $snapshot['manuscript']['translation']=$translated;
        return [...($type==='TRANSLATION' ? ['translationMetadata'=>$translated,'documentTranslationCompleted'=>true] : []),'submissionExternalId'=>'217','reviewAssignmentExternalId'=>$binding['externalAssignmentId'],'reviewRound'=>$binding['reviewRound'],'assignmentToken'=>$binding['assignmentToken'],'assignmentType'=>$type,'sourceDigest'=>str_repeat('a',64),'authorAndEditorComment'=>'Public comment','editorOnlyComment'=>'Confidential comment','reviewFormResponses'=>[],
            'files'=>[['fileName'=>($type==='TRANSLATION'?'translation-hu-'.($bound['targetLanguage'] ?? 'en').'-assignment-task':'returned').'.docx','mediaType'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','contentBase64'=>base64_encode('Word fixture bytes')],['fileName'=>($type==='TRANSLATION'?'translation-hu-'.($bound['targetLanguage'] ?? 'en').'-assignment-task':'returned').'.omi-review.json','mediaType'=>'application/vnd.openmanuscript.review+json','contentBase64'=>base64_encode(json_encode($snapshot))]]];
    }
    $count=0;
    foreach(['SCIENTIFIC_REVIEW','LANGUAGE_REVIEW','TRANSLATION'] as $type) {
        State::reset(); State::$stage=$type==='SCIENTIFIC_REVIEW'?3:4;
        $binding=bindTask($controller,$type);
        check($binding['anonymityMode']===($type==='SCIENTIFIC_REVIEW'?'double_blind':'open'),'Incorrect anonymity');
        $input=resultInput($binding,$type);
        $result=$controller->assignmentResult(new \Illuminate\Http\Request($input));
        check($result->status===200,'Writeback failed: '.json_encode($result->data));
        check(count(State::$files)===2,'Both native files must be stored');
        check(State::$files[1]['uploaderUserId']===20,'Wrong participant identity');
        check(State::$files[1]['fileStage']===($type==='SCIENTIFIC_REVIEW'?5:9),'Wrong native file stage');
        check(count(State::$comments)===2 && State::$comments[0]['viewable']===true && State::$comments[1]['viewable']===false,'Comment visibility was not preserved');
        $again=$controller->assignmentResult(new \Illuminate\Http\Request($input));
        check($again->status===200 && $again->data['unchanged']===true && count(State::$files)===2 && count(State::$comments)===2,'Retry duplicated result');
        $input['sourceDigest']=str_repeat('b',64);
        $changed = $controller->assignmentResult(new \Illuminate\Http\Request($input));
        check($changed->status===($type==='SCIENTIFIC_REVIEW'?409:200),'Incorrect changed-content lifecycle policy');
        if ($type==='SCIENTIFIC_REVIEW') {
            $method = new \ReflectionMethod($controller, 'reviewAssignmentForClaims');
            check($method->invoke($controller, ['actorMode'=>'review','scope'=>['review.manuscript.read'],'reviewAssignment'=>['externalId'=>'31'],'actor'=>['externalId'=>'20']], 217)===null,'Submitted scientist retained manuscript access');
        } else check(count(State::$files)===4,'An open collaborator revision did not create a native version');
        $input['submissionExternalId']='999';
        check($controller->assignmentResult(new \Illuminate\Http\Request($input))->status===404,'Cross-submission write accepted');
        echo $type . " native files, privacy, receipt and retry checks passed\n"; $count++;
    }
    State::reset(); $binding=bindTask($controller,'TRANSLATION'); $input=resultInput($binding,'TRANSLATION');
    State::$editor=false; check($controller->assignmentResult(new \Illuminate\Http\Request($input))->status===403,'Revoked editor still authorized');
    State::$editor=true; State::$failSecond=true; $beforeSettings=State::$settings; $beforeMetadata=State::$publication->data;
    $failed = $controller->assignmentResult(new \Illuminate\Http\Request($input));
    check($failed->getStatusCode()===422 && $failed->data['error']['code']==='workflow_file_validation_failed' && $failed->data['error']['fields']===['invalid'=>true], 'Native validation response was lost');
    check(State::$files===[] && State::$comments===[] && State::$settings===$beforeSettings && State::$publication->data===$beforeMetadata && State::$storage===[],'Partial return or physical orphan was committed');
    State::reset(); State::$stage=3;
    $binding=bindTask($controller,'SCIENTIFIC_REVIEW'); $input=resultInput($binding,'SCIENTIFIC_REVIEW');
    $input['reviewRound']=3;
    check($controller->assignmentResult(new \Illuminate\Http\Request($input))->status===403,'Wrong round accepted');
    $input=resultInput($binding,'SCIENTIFIC_REVIEW');
    $structured=json_decode(base64_decode($input['files'][1]['contentBase64']),true);
    $structured['manuscript']['authors']=[['name'=>'Author identity']];
    $input['files'][1]['contentBase64']=base64_encode(json_encode($structured));
    check($controller->assignmentResult(new \Illuminate\Http\Request($input))->status===422,'Scientific author disclosure accepted');
    check(State::$files===[],'Rejected payload created a file');
    State::reset(); $binding=bindTask($controller,'TRANSLATION'); $input=resultInput($binding,'TRANSLATION');
    $structured=json_decode(base64_decode($input['files'][1]['contentBase64']),true);
    $structured['manuscript']['annotations']=[['visibility'=>'editor_only','body'=>'Private']];
    $input['files'][1]['contentBase64']=base64_encode(json_encode($structured));
    check($controller->assignmentResult(new \Illuminate\Http\Request($input))->status===422,'Private annotation in shareable file accepted');
    echo "Revocation, round isolation, disclosure rejection and rollback checks passed\n";

    foreach (['en','de'] as $target) {
        State::reset(); $binding=bindTask($controller,'TRANSLATION',false,$target); $input=resultInput($binding,'TRANSLATION');
        $input['files']=[]; $input['documentTranslationCompleted']=false;
        $result=$controller->assignmentResult(new \Illuminate\Http\Request($input));
        check($result->status===200,'Metadata-only return failed: '.json_encode($result->data));
        check(State::$files===[] && State::$storage===[],'Metadata-only task uploaded a manuscript');
        check(State::$publication->data['title'][$target]==='Translated title','Wrong translation locale');
        check(State::$publication->data['title']['hu']==='Magyar cím','Source title overwritten');
        check(State::$publication->data['abstract']['hu']==='Magyar összefoglaló','Source abstract overwritten');
        check(State::$publication->data['keywords']['hu']===['magyar'],'Source keywords overwritten');
        if ($target==='en') check(State::$publication->data['title']['de']==='Deutscher Titel','English overwrote German title');
    }
    State::reset(); $binding=bindTask($controller,'TRANSLATION'); $input=resultInput($binding,'TRANSLATION');
    $input['translationMetadata']['targetLanguage']='de';
    check($controller->assignmentResult(new \Illuminate\Http\Request($input))->status===422,'Wrong language metadata accepted');
    foreach (['title','abstract','keywords'] as $field) {
        $input=resultInput($binding,'TRANSLATION'); $input['translationMetadata'][$field]=$field==='keywords'?[]:'';
        check($controller->assignmentResult(new \Illuminate\Http\Request($input))->status===422,'Incomplete translation accepted: '.$field);
    }
    $input=resultInput($binding,'TRANSLATION'); $input['documentTranslationCompleted']=false;
    check($controller->assignmentResult(new \Illuminate\Http\Request($input))->status===422,'Unfinished full-text translation accepted');
    echo "Translation locale isolation, mandatory metadata and full-text scope checks passed\n";
}
