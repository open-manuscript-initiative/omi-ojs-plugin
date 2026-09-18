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

echo "Contributor metadata contract checks passed\n";
