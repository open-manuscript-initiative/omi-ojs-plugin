<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../classes/Adapters/Ojs35Adapter.php');
if ($source === false) {
    fwrite(STDERR, "Unable to read contributor adapter.\n");
    exit(1);
}

$required = [
    "'preferredPublicName'",
    "'email'",
    "'affiliation'",
    "'affiliations'",
    "'country'",
    "'url'",
    "'biography'",
    "'competingInterests'",
    "'primaryContact'",
    "'includeInBrowse'",
    "'creditRoles'",
    "'identifiers'",
    "getAffiliations",
    "getRor",
    "getCreditRoles",
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Contributor metadata contract is missing: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($source, "'scope' => ['type' => 'submission'")) {
    fwrite(STDERR, "Contributor payload lost submission scope binding.\n");
    exit(1);
}


$plugin = file_get_contents(__DIR__ . '/../StudioIntegrationPlugin.php');
$controller = file_get_contents(__DIR__ . '/../StudioIntegrationApiController.php');
if ($plugin === false || $controller === false) {
    fwrite(STDERR, "Unable to read integration scope sources.\n");
    exit(1);
}

if (!preg_match("/:\\s*\\[\\s*'metadata\\.read',\\s*'contributors\\.read',\\s*'files\\.read'/s", $plugin)) {
    fwrite(STDERR, "Author launch does not grant contributor reads.\n");
    exit(1);
}

$reviewerStart = strpos($controller, 'public function reviewers');
$reviewerEnd = $reviewerStart === false
    ? false
    : strpos($controller, 'public function ', $reviewerStart + 24);
$reviewerBlock = $reviewerStart === false
    ? ''
    : substr(
        $controller,
        $reviewerStart,
        $reviewerEnd === false ? null : $reviewerEnd - $reviewerStart
    );
if (
    !str_contains($reviewerBlock, "hasScope(\$claims, 'review.identity.read')") ||
    str_contains($reviewerBlock, "contributors.read")
) {
    fwrite(STDERR, "Reviewer identity access is not isolated from contributor scope.\n");
    exit(1);
}

echo "Contributor metadata and identity-scope contract checks passed\n";
