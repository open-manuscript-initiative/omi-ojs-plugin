<?php
declare(strict_types=1);
$source = file_get_contents(dirname(__DIR__) . '/StudioIntegrationApiController.php');
$start = strpos($source, 'public function publicationArtifact(');
$end = strpos($source, 'public function htmlGalley(', $start);
$method = substr($source, $start, $end - $start);
foreach ([
    "'expectedIssueId' => 'sometimes|integer|min:1'",
    "'expectedInstallationId' => 'required_with:expectedIssueId|string|max:255'",
    "Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN",
    "hash_equals(",
    '$issue->getPublished()',
    "Submission::STATUS_SCHEDULED : Submission::STATUS_QUEUED",
    "(int)\$publication->getData('issueId') !== \$expectedIssueId",
    "isset(\$data['expectedWorkflowToken'])",
    "DB::table('issues')->where('issue_id', \$expectedIssueId)->lockForUpdate()",
    '[$submission, $publication] = $authorize();',
    "'isApproved' => false",
    "'published' => false",
] as $needle) {
    if (!str_contains($method, $needle)) throw new RuntimeException('Missing issue transfer boundary: ' . $needle);
}
echo "Planned issue artifact target contract passed.\n";
