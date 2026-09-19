# Native review and author revision writeback

Open Manuscript Studio can return workflow files to OJS without bypassing the
native OJS 3.5 submission-file model. OJS remains authoritative for the review
assignment, review round, author stage assignment, revision eligibility and
stored file record.

Both endpoints use the existing server-to-server OMI HMAC authentication:

- `X-OMI-Installation`
- `X-OMI-Timestamp`
- `X-OMI-Signature`

The signature covers the HTTP method, request path and SHA-256 hash of the exact
JSON request body.

## Reviewer returned file

```text
POST /api/v1/omi-integration/review-file
```

Example request:

```json
{
  "submissionExternalId": "217",
  "reviewAssignmentExternalId": "31",
  "reviewRound": 1,
  "fileName": "reviewer-corrections.docx",
  "mediaType": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "contentBase64": "..."
}
```

OJS independently verifies that:

- the submission belongs to the current journal;
- the review assignment belongs to that submission;
- the requested round matches both the assignment and its native review round;
- the review round is the external-review stage;
- the assignment is not declined, complete, thanked or cancelled.

The returned file is created as a native
`SUBMISSION_FILE_REVIEW_ATTACHMENT`, associated with the concrete
`ReviewAssignment`. The reviewer recorded by OJS is used as the uploader.
The endpoint never accepts a client-selected reviewer identity.

This follows the same association model used by the native OJS reviewer upload
wizard and prevents a file from being attached to another submission,
assignment or round.

## Author revision

```text
POST /api/v1/omi-integration/author-revision
```

Example request:

```json
{
  "submissionExternalId": "217",
  "authorExternalId": "14",
  "reviewRound": 1,
  "genreExternalId": 1,
  "fileName": "article-revised.docx",
  "mediaType": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "contentBase64": "..."
}
```

OJS independently verifies that:

- the submission belongs to the current journal;
- the supplied author is assigned to this submission in external review;
- the requested round is the latest native external review round;
- that round contains an OJS decision which permits author revision upload;
- the selected file genre exists in the journal and is not a dependent-file
  genre.

The decision check mirrors OJS 3.5
`SubmissionFileStageAccessPolicy` and accepts the same external-review
decision types: accept, pending revisions, new external round and resubmit.

The returned author file is created as
`SUBMISSION_FILE_REVIEW_REVISION`, associated with the native
`ReviewRound`. A new submission-file record is created. The original source
file is never overwritten.

Adding the file through `Repo::submissionFile()->add()` also preserves OJS's
native revision logging, review-round status recalculation and revision
notifications.

## File validation and storage

Both endpoints use the `omi-workflow-file/1` transfer envelope.

The plugin:

- rejects path traversal and control characters in file names;
- validates the declared media type;
- uses strict Base64 decoding;
- limits one transferred workflow file to 64 MiB;
- calculates SHA-256 and byte length for the receipt;
- stores bytes through OJS's native file service;
- lets OJS detect and persist the actual MIME type;
- runs `Repo::submissionFile()->validate()` before creating the
  `SubmissionFile`.

The response includes the native OJS submission-file ID, detected MIME type,
byte length and SHA-256 digest.

## Capabilities

OJS advertises:

- `author.revision.write`
- `review.revision.write`
- `review.files.write`

Reviewer launch assertions also carry `review.files.write` once this endpoint
is available.

These capabilities permit file return only within the native OJS workflow
boundaries described above. They do not grant unrestricted submission-file
write access.
