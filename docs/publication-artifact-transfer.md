# Publication artifact transfer

Plugin version **1.5.0.0** adds an editor-authenticated publication artifact
transfer protocol for Open Manuscript Studio.

The endpoint is available inside the journal-scoped plugin API:

```text
POST /api/v1/omi-integration/publication-artifact
```

OJS remains the publication authority. A successful transfer creates or updates
an **unapproved production galley** only. It does not publish the article,
approve the galley, change the publication status, or bypass OJS editorial
authorization.

## Capability discovery

`GET /api/v1/omi-integration` advertises:

```text
editor.publication-artifact.write
publication.html.write
publication.jats.write
publication.pdf.write
publication.provenance.verify
```

The `publicationArtifacts` object declares the protocol, required provenance
model and available formats. HTML availability is reported separately because
HTML galleys require the OJS HTML Article Galley plugin to be enabled.

Supported transfer formats are:

| Format | Media type | Maximum decoded size | OJS galley label |
| --- | --- | ---: | --- |
| `html` | `text/html` | 8 MiB | HTML |
| `jats` | `application/xml` | 8 MiB | JATS XML |
| `pdf-print` | `application/pdf` | 32 MiB | PDF |
| `pdf-interactive` | `application/pdf` | 32 MiB | PDF (Interactive) |

The older `omi-html-galley/1` endpoint remains available for backwards
compatibility.

## Inspect before transfer

The client first sends:

```json
{
  "action": "inspect",
  "submissionId": 217,
  "manuscriptId": "omi-manuscript-id"
}
```

Inspection succeeds only when:

- the plugin is enabled for the current journal;
- the current user is an authenticated editorial user;
- the editor can access the submission in the Production stage;
- the target is the current unpublished publication version.

The response returns the current publication ID, supported submission locales,
eligible file genres, available artifact formats and the required provenance
model.

## Transfer request

A transfer includes the exact artifact bytes as base64 plus the corresponding
Studio publication-build manifest:

```json
{
  "action": "transfer",
  "submissionId": 217,
  "publicationId": 301,
  "manuscriptId": "omi-manuscript-id",
  "locale": "en",
  "genreId": 1,
  "format": "jats",
  "mediaType": "application/xml",
  "fileName": "article.jats.xml",
  "artifactBase64": "...",
  "build": {
    "model": "omi-publication-build",
    "version": "0.1.0",
    "id": "urn:omi:publication-build:sha256:..."
  },
  "confirmed": true
}
```

The complete `build` object is required; the shortened example above is not a
valid transfer body.

## Provenance verification

The plugin independently verifies the Studio provenance before it writes an OJS
file. The following must agree:

- provenance model and version;
- OMI manuscript ID;
- committed revision and state digest presence;
- publication-profile identity and digest;
- output format;
- media type;
- original artifact filename;
- exact decoded byte length;
- SHA-256 digest of the transferred bytes;
- Studio application and renderer identity;
- optional renderer-input provenance;
- deterministic publication-build URN.

The deterministic build identifier is recalculated from the canonical
publication-build identity rather than trusted as an opaque client value.

A provenance mismatch returns HTTP 422 and leaves no galley, submission-file or
physical-file residue.

The `.omi-build.json` object is **verified but not published as a separate
galley**. The receipt returns the verified build ID and artifact SHA-256. This
prevents an internal provenance document from accidentally becoming a public
article representation.

## Format safety checks

### HTML

The existing restricted HTML policy remains in effect:

- no scripts, forms, frames, embedded objects or SVG;
- no event-handler attributes;
- no external CSS or image resources;
- images must be embedded raster data URIs;
- only HTTP(S), mail and internal links are accepted.

Because provenance covers the exact bytes, the new protocol does not mutate the
HTML after verification.

### JATS

The plugin does not attempt to replace Studio's full JATS validator. Studio must
already have produced a validated JATS build. OJS additionally checks that:

- the XML is well-formed;
- the document root is `article`;
- `dtd-version="1.4"`;
- entity declarations and internal DTD subsets are rejected;
- if a DOCTYPE is present, it must be the pinned JATS 1.4 Article Authoring
  MathML 3 DTD used by Studio.

The verified provenance binds the transferred XML bytes to the Studio build that
passed its release gates.

### PDF

PDF transfer checks the PDF signature and end-of-file marker in addition to
provenance, byte length and SHA-256 verification.

## Idempotency and editorial history

Galley identity is stable for:

```text
manuscript ID + locale + publication format
```

An identical retry does not create another proof file. A changed artifact keeps
the same logical galley but creates a new proof submission file and repoints the
unapproved galley, preserving prior OJS proof-file history.

Print and interactive PDFs use separate galley identities and can coexist.

## Concurrency and authority

Immediately before persistence the plugin locks the submission and publication
rows and repeats the authorization/state checks. If the article leaves the
expected unpublished Production state between inspection and transfer, the
operation is rejected.

This protocol never:

- publishes an article;
- approves a galley;
- changes an editorial decision;
- accepts a historical/non-current publication version;
- grants access based only on a submission ID;
- treats the OMI shared integration secret as an editor credential.

The editor authenticates through the native OJS API/session authorization used
by the plugin endpoint.
