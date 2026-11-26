<?php
require_once __DIR__ . '/../classes/Core/Base64Url.php';
require_once __DIR__ . '/../classes/Core/LaunchToken.php';

use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$now = time();
$claims = [
    'protocol' => 'omi-integration/1',
    'profile' => 'omi-integration/1/ojs',
    'installationId' => 'journal-1',
    'context' => ['externalId' => '1'],
    'issue' => ['externalId' => '41'],
    'actor' => ['externalId' => '3'],
    'actorMode' => 'editor',
    'scope' => ['issue.import.read'],
    'iat' => $now,
    'exp' => $now + 300,
    'nonce' => 'test-nonce',
];
$token = LaunchToken::issue($claims, 'test-secret');
check(LaunchToken::verify($token['payload'], $token['signature'], 'test-secret', 1) === $claims, 'Issue assertion must round-trip.');
check(LaunchToken::verify($token['payload'], $token['signature'], 'test-secret', 2) === null, 'Cross-journal assertion must fail.');
check(LaunchToken::verify($token['payload'], $token['signature'], 'other-secret', 1) === null, 'Invalid signature must fail.');
$claims['iat'] = $now - 600;
$claims['exp'] = $now - 300;
$expired = LaunchToken::issue($claims, 'test-secret');
check(LaunchToken::verify($expired['payload'], $expired['signature'], 'test-secret', 1) === null, 'Expired issue assertion must fail.');

$source = file_get_contents(__DIR__ . '/../StudioIntegrationApiController.php');
foreach ([
    "Route::get('issue-import/manifest'",
    "Route::get('issue-import/articles/{submissionId}'",
    "Route::get('issue-import/articles/{submissionId}/files/{submissionFileId}/content'",
    "['issue.import.read']",
    'isset($claims[\'submission\'])',
    'Repo::user()->get($actorId)',
    'Role::ROLE_ID_MANAGER',
    'Submission::STATUS_SCHEDULED',
    'SUBMISSION_FILE_PRODUCTION_READY',
    'SUBMISSION_FILE_COPYEDIT',
    'SUBMISSION_FILE_FINAL',
    'SUBMISSION_FILE_SUBMISSION',
    'issueSourceFiles($adapter, $submission)',
] as $required) {
    check(str_contains($source, $required), 'Missing issue import boundary: ' . $required);
}
$start = strpos($source, 'private function issueSourceFiles(');
$end = strpos($source, 'public function issueArticle(', $start);
$policy = substr($source, $start, $end - $start);
foreach (['SUBMISSION_FILE_REVIEW_FILE', 'SUBMISSION_FILE_REVIEW_ATTACHMENT', 'SUBMISSION_FILE_QUERY', 'SUBMISSION_FILE_INTERNAL_REVIEW_FILE'] as $forbidden) {
    check(!str_contains($policy, $forbidden), 'Private workflow stage must not be eligible: ' . $forbidden);
}
echo "Issue assertion and static route/source-policy contracts passed.\n";
