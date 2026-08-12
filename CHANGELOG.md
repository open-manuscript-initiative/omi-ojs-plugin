# Changelog

## 1.1.16 — 2026-08-12

- Fixed the OJS browser launcher to unwrap PKP `JSONMessage` responses and read `content.launchUrl` as well as a top-level `launchUrl`.
- Fixed a false “The OJS integration did not return a Studio launch URL” error when the backend had actually returned a valid launch URL.
- Made launch-mode resolution role-aware on the server: editorial roles always receive the normal editor launch, even while viewing the Review stage.
- Reviewer mode is only selected for reviewer-role users requesting the reviewer launch path.
- Editor launches retain `contributors.read`; reviewer launches remain isolated from contributor metadata.
- Added the cache-busted `studioIntegration-1.1.6.js` launcher asset.

## 1.1.14 — 2026-08-12

- Added a dedicated Studio launcher to the OJS reviewer page.
- Added reviewer-specific launch authorization: the current user must have the reviewer role and an assignment to the requested submission.
- Reviewer launches open the Studio Peer Review workspace directly with `?review=1`.
- Reviewer launches deliberately do not issue the normal OJS metadata/contributor launch assertion, preventing author-identifying contributor metadata from being exposed through this path.
- Added English, Hungarian and German reviewer-launch labels.
- Updated the browser launcher to recognize reviewer URLs and request `mode=review`.

## 1.1.3 — 2026-08-08

- Changed the Studio launcher to request a fresh signed launch URL only when the user clicks the button.
- Added visible loading and error states instead of silently disabling or removing the launcher when the launch request fails.
- Added browser console diagnostics for failed launch requests.
- Switched the launcher to same-tab navigation after successful launch URL creation, avoiding popup-blocker ambiguity.
- Added `cache: no-store` for launch URL requests and bumped frontend asset cache keys.

## 1.1.2 — 2026-08-08

- Fixed the OJS 3.5 workflow launcher so it no longer depends on PHP resolving the submission ID during template rendering.
- Added an authenticated, authorization-checked launch URL endpoint inside the plugin.
- Updated the browser integration client to resolve the active submission from OJS workflow URLs and request a short-lived launch URL on demand.
- Added support for dynamic OJS workflow navigation through History API and DOM mutation detection.
- Kept the shared secret and HMAC signing server-side; the browser receives only a short-lived signed Studio launch URL.
- Added a loading state for the launcher while the authorized launch URL is being created.
- Added cache-busting to the launcher JavaScript and stylesheet URLs.

## 1.1.1 — 2026-08-07

- Fixed OJS 3.5 localization compatibility by adding valid Gettext PO headers to the English, Hungarian and German locale files.
- Declared UTF-8 encoding and the correct language metadata in each locale.
- Corrected a Hungarian translation typo in the launch-token lifetime setting.
- No Integration API or data-model changes; this is a localization compatibility patch release.

## 1.1.0 — 2026-08-07

- Added reusable PKP connector core primitives for Base64URL, HMAC launch tokens and JSON API responses.
- Added OJS 3.5 adapter for submission metadata, contributors and submission-file listing.
- Added OMI Integration API v1 capability discovery.
- Reworked launch claims to use `omi-integration/1` and `omi-integration/1/ojs` identifiers.
- Added stable installation ID support.
- Limited initial API capabilities to implemented read operations; binary transfer, review, revision and publication writes are not falsely advertised.
