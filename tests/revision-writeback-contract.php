<?php

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$source = file_get_contents(__DIR__ . '/../StudioIntegrationApiController.php');
check(is_string($source) && $source !== '', 'Unable to read StudioIntegrationApiController.php');

check(
    str_contains($source, "Route::post('review-file',"),
    'Reviewer file writeback route is missing.'
);
check(
    str_contains($source, "Route::post('author-revision',"),
    'Author revision writeback route is missing.'
);
check(
    str_contains($source, "'review.files.write'"),
    'Operational reviewer file-write capability is not advertised.'
);

$reviewStart = strpos($source, 'public function reviewFile(');
$authorStart = strpos($source, 'public function authorRevision(');
$recommendationStart = strpos($source, 'private function reviewerRecommendationCapabilities(');
check($reviewStart !== false && $authorStart !== false && $recommendationStart !== false, 'Writeback methods are missing.');

$reviewMethod = substr($source, $reviewStart, $authorStart - $reviewStart);
$authorMethod = substr($source, $authorStart, $recommendationStart - $authorStart);

foreach ([
    'authorizeServiceRequest',
    'reviewAssignmentExternalId',
    'reviewRound',
    'reviewAssignmentAllowsFileWrite',
    'SUBMISSION_FILE_REVIEW_ATTACHMENT',
    'ASSOC_TYPE_REVIEW_ASSIGNMENT',
] as $required) {
    check(str_contains($reviewMethod, $required), "Reviewer writeback is missing {$required}.");
}

foreach ([
    'authorizeServiceRequest',
    'authorExternalId',
    'getAccessibleWorkflowStages',
    'getLastReviewRoundBySubmissionId',
    'Decision::PENDING_REVISIONS',
    'Decision::RESUBMIT',
    'SUBMISSION_FILE_REVIEW_REVISION',
    'ASSOC_TYPE_REVIEW_ROUND',
] as $required) {
    check(str_contains($authorMethod, $required), "Author revision writeback is missing {$required}.");
}

check(
    !str_contains($authorMethod, 'Repo::submissionFile()->edit('),
    'Author revision writeback must not replace an existing source file.'
);
check(
    str_contains($source, 'ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_COMPLETE') &&
    str_contains($source, 'ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_THANKED') &&
    str_contains($source, 'ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_CANCELLED') &&
    str_contains($source, 'ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_DECLINED'),
    'Reviewer writeback must mirror native OJS non-writable assignment statuses.'
);
check(
    str_contains($source, 'Repo::submissionFile()->add('),
    'Workflow writeback must create a native OJS SubmissionFile.'
);
check(
    str_contains($source, 'Repo::submissionFile()->validate('),
    'Workflow writeback must use native OJS submission-file validation.'
);

echo "Revision writeback contract checks passed\n";
