# Changelog

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
