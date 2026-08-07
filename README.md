# Open Manuscript Studio Integration for OJS 3.5 — 1.1.0

This repository contains the OJS connector for the **Open Manuscript Initiative (OMI)** and **Open Manuscript Studio**.

The plugin is the first implementation of the **OMI Integration API v1 / OJS profile** (`omi-integration/1/ojs`). It is intentionally implemented as a separate OJS generic plugin so that OJS and Open Manuscript Studio remain independent applications with separate databases and release cycles.

## Implemented capabilities

- `launch`
- `metadata.read`
- `contributors.read`
- `files.read` (metadata/list only; binary transfer is intentionally not advertised yet)
- HMAC-SHA256 signed, short-lived launch assertions
- stable OJS installation identity
- OJS journal → OMI context mapping
- OJS submission → OMI submission mapping
- settings UI with English, Hungarian and German locale files

## Security model

The launch assertion is scoped to one journal, submission and OJS user and expires after the configured TTL. Studio can reuse the assertion during the initial import by sending:

```text
Authorization: OMI <payload>.<signature>
```

The API does not expose OJS database credentials or private file-system paths. The integration is designed around application-level authorization and the OMI principle that publishing systems and manuscript data stores remain separated.

## Adapter endpoints

Inside a journal context:

```text
/omiIntegration/capabilities
/omiIntegration/submission
/omiIntegration/contributors
/omiIntegration/files
```

The last three require a signed launch assertion.

## Installation

For an OJS plugin upload, package the repository contents inside a top-level `studioIntegration/` directory and create a `.tar.gz` archive. Upload it in OJS under **Settings → Website → Plugins → Upload A New Plugin** (wording may vary by locale), then enable the generic plugin and open its settings.

Configure the Studio URL and copy the same shared secret to the Studio OJS connector configuration. A stable installation ID is recommended for production.

## Compatibility

- Open Journal Systems: OJS 3.5.x
- Plugin type: Generic Plugin
- OMI protocol: `omi-integration/1`
- OJS profile: `omi-integration/1/ojs`

## Deferred capabilities

Binary file transfer, revision upload, review-assignment endpoints, structured review return, and publication export are deliberately not advertised until their OJS 3.5 authorization and workflow mappings are implemented and tested.

## Related projects

- Open Manuscript Initiative documentation: https://openmanuscript.org/
- Open Manuscript Studio: https://github.com/open-manuscript-initiative/open-manuscript-studio
- OMI specifications: https://github.com/open-manuscript-initiative/omi

## License

GNU General Public License v3.0. See `LICENSE`.