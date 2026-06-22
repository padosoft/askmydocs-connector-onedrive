# Changelog

All notable changes to `padosoft/askmydocs-connector-onedrive` are documented here.
This file follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] — 2026-06-22

### Changed

- Adopt `BaseConnector::resolveProjectKey()` from connector-base `^1.3` for multi-account / project binding. The ingest project key now resolves to the installation's explicit `project_key` when set, otherwise the host's `kb.ingest.default_project` config (itself defaulting to the literal `default`). This replaces the previous `connector-onedrive` synthetic-project fallback so multiple installations can bind to distinct tenant projects.
- Bump `padosoft/askmydocs-connector-base` requirement to `^1.3`.

## [1.0.0] - 2026-05-12

### Added

- Initial extraction from AskMyDocs v4.5/W5 inline connector framework.
- `OneDriveConnector` — Microsoft identity platform v2.0 OAuth2 (authorize / callback / refresh with `prompt=consent` for reliable refresh-token issuance), recursive folder walk for full sync, delta-cursor incremental sync, deletion reconciliation via `softDeleteByRemoteId('onedrive_item_id', ...)`, best-effort `/me/revokeSignInSessions` on disconnect, health probe via `/me`.
- `Support\MicrosoftGraphPaginator` — `@odata.nextLink` walker with `@odata.deltaLink` sidechannel for incremental sync persistence. Eager `walk()` + lazy `walkLazy()` traversal modes, mirrors the Notion + GoogleDrive paginator contract.
- Supported MIME types: `text/markdown`, `text/plain`, `application/pdf`. Office / Outlook formats reserved for a future release.
- `OneDriveServiceProvider` — auto-registered via Laravel package discovery; merges per-package config under `connectors.providers.onedrive`, exposes `connector-onedrive-config` + `connector-onedrive-assets` publish tags.
- Composer `extra.askmydocs.connectors` discovery — the base package's `ConnectorRegistry` picks up `OneDriveConnector` automatically.
- Test matrix — PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 on push and pull-request via GitHub Actions.
- Opt-in live test suite gated by `CONNECTOR_ONEDRIVE_LIVE=1`.
