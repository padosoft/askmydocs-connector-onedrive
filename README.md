<h1 align="center">askmydocs-connector-onedrive</h1>

<p align="center">
  <strong>Microsoft OneDrive connector for AskMyDocs — OAuth2 via Microsoft identity platform v2.0, Microsoft Graph delta-cursor incremental sync, both work and personal accounts.</strong><br/>
  Drop-in Laravel package. <code>composer require</code> it from any AskMyDocs install and the OneDrive connector appears in the admin UI on the next request.
</p>

<p align="center">
  <a href="https://github.com/padosoft/askmydocs-connector-onedrive/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-onedrive/tests.yml?branch=main&label=tests"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-onedrive"><img alt="Packagist version" src="https://img.shields.io/packagist/v/padosoft/askmydocs-connector-onedrive.svg?label=packagist"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-onedrive"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/padosoft/askmydocs-connector-onedrive.svg?label=downloads"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
</p>

---

## Table of contents

1. [Why this package](#why-this-package)
2. [Features](#features)
3. [AI vibe-coding pack included](#-ai-vibe-coding-pack-included)
4. [Architecture at a glance](#architecture-at-a-glance)
5. [Installation](#installation)
6. [Credential setup (junior-proof, step by step)](#credential-setup-junior-proof-step-by-step)
7. [Activation inside AskMyDocs](#activation-inside-askmydocs)
8. [What gets ingested](#what-gets-ingested)
9. [Sync semantics](#sync-semantics)
10. [Supported MIME types](#supported-mime-types)
11. [Testing](#testing)
12. [Live testsuite](#live-testsuite)
13. [Troubleshooting](#troubleshooting)
14. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge compilation system. Out of the box it ingests markdown from disk, the chat UI, an HTTP API, and a Git-driven workflow — but a huge amount of enterprise knowledge lives in OneDrive for Business + personal OneDrive folders.

This package is the smallest possible surface for shipping that integration:

- An `OneDriveConnector` that implements `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`.
- Microsoft identity platform v2.0 OAuth — supports `common` (work + personal), `consumers` (personal only), `organizations` (work only), and per-tenant GUIDs (enterprise SSO).
- A `MicrosoftGraphPaginator` that walks `@odata.nextLink` semantics and surfaces the `@odata.deltaLink` for incremental sync persistence.
- Recursive folder traversal for full sync; delta-cursor sync for incremental.
- Deletion reconciliation via Graph's `deleted` facet → host's `softDeleteByRemoteId` helper.
- A composer.json that auto-registers via `extra.askmydocs.connectors`. Zero edits to your host app's config required.

> **`composer require padosoft/askmydocs-connector-onedrive`. Done.**

## Features

- 🔌 **Zero-config installation** — composer-extra discovery auto-registers the connector at boot.
- 🔐 **Microsoft identity platform v2.0** — OAuth2 with CSRF state token + `prompt=consent` for reliable refresh-token issuance, automatic refresh when access token expires (~1h lifetime).
- ♻️ **Delta-cursor incremental sync** — Graph's `/me/drive/root/delta` returns ONLY items that changed since the persisted cursor. Daily syncs cost one round-trip on quiet drives.
- 🗑️ **Deletion reconciliation** — items carrying a `deleted` facet route through `softDeleteByRemoteId('onedrive_item_id', ...)`.
- 📁 **Recursive folder walk** for full sync — bounded at 8 levels deep to keep memory + bandwidth predictable.
- 🚦 **MIME filtering up front** — only `text/markdown`, `text/plain`, `application/pdf` get downloaded. Office / Outlook files surface as TODO markers until host ingest pipelines ship docx/xlsx/pptx extractors.
- 🧠 **Source-aware metadata** — item id, MIME type, web URL, size, folder path, owner email, shared-with list, last-modified-by all surface to the host's reranker via `SourceAwareMetadataBuilder`.
- 🚦 **Failure-loud exception taxonomy** — 401 / 403 → `ConnectorAuthException`, other non-2xx → `ConnectorApiException`, pagination cap reached → `ConnectorPaginationLimitException`.
- 🏢 **Per-tenant isolated** — every credential read and ingestion dispatch is scoped to the active `TenantContext`. Multi-tenant OneDrive deployments work out of the box.
- 🧪 **Test-friendly** — `Http::fake()` feature tests against the spy ingestion contract; opt-in live test that hits real `graph.microsoft.com` when `CONNECTOR_ONEDRIVE_LIVE=1`.

## 🚀 AI vibe-coding pack included

This package was built with a vibe-coding pack of Claude Code skills and rules (`.claude/` directory in the parent AskMyDocs repo) that codify the architectural invariants — the IoC contract that keeps this package standalone-agnostic, the Microsoft Graph delta-link quirk, the failure-loud exception taxonomy, the bounded folder-recursion contract.

If you're using Claude Code to fork or extend this package, point the agent at the parent repo's `.claude/` pack and it stays inside the invariants automatically. No tribal-knowledge drift.

## Architecture at a glance

```
                ┌──────────────────────────────┐
Composer        │ padosoft/askmydocs-          │
require ───────▶│ connector-onedrive           │
                │ (this package)               │
                └────────────┬─────────────────┘
                             │
                             │ auto-registered via composer
                             │ extra.askmydocs.connectors
                             ▼
                ┌──────────────────────────────┐
                │ padosoft/askmydocs-connector-│
                │ base v1.1.1+                 │
                │ ConnectorRegistry            │
                └────────────┬─────────────────┘
                             │
                             │ resolves OneDriveConnector
                             ▼
                ┌──────────────────────────────┐
                │ OneDriveConnector::syncFull  │
                │  • walkFolder (recursive)    │
                │  • GET /me/drive/items/...   │
                │  • MicrosoftGraphPaginator   │
                │  • SourceAwareMetadata       │
                │ OneDriveConnector::syncIncr  │
                │  • /me/drive/root/delta      │
                │  • soft-delete on `deleted`  │
                └────────────┬─────────────────┘
                             │
                             │ ConnectorIngestionContract
                             │ (IoC bridge — host implements)
                             ▼
                ┌──────────────────────────────┐
                │ Host app (AskMyDocs):        │
                │  • Storage::put → KB disk    │
                │  • IngestDocumentJob         │
                │  • kb_canonical_audit row    │
                │  • PII redactor at boundary  │
                └──────────────────────────────┘
```

The IoC bridge is the key design decision: this package never imports any host class. It dispatches every host-side concern through `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`. The host binds its own implementation in a service provider; this package stays standalone-agnostic.

## Installation

```bash
composer require padosoft/askmydocs-connector-onedrive
```

The package follows Laravel's auto-discovery convention. After install:

```bash
php artisan vendor:publish --tag=connector-onedrive-config   # optional — for env-var overrides
php artisan vendor:publish --tag=connector-onedrive-assets   # optional — copies onedrive.svg to public/connectors
```

The `connector-base` migrations ship in the parent package and auto-load via its service provider; no extra `migrate` step is needed.

## Credential setup (junior-proof, step by step)

OneDrive uses Microsoft identity platform v2.0. You register an app in Azure Active Directory, configure permissions, generate a client secret, and put the values in `.env`. Follow EVERY step — skipping a checkbox in the Azure portal is the single most common cause of `invalid_client` or `AADSTS65001` later.

### 1. Open the Azure portal

1. Open <https://portal.azure.com> in your browser. Sign in with the Microsoft account whose OneDrive (or whose AAD tenant's OneDrive) you want to ingest.

### 2. Register the application

1. Search bar (top) → type **"App registrations"** → click the result.
2. Click **"+ New registration"** (top-left).
3. **Name**: `AskMyDocs` (any human-friendly label).
4. **Supported account types**: pick the row that matches your deployment shape:
    - **Accounts in any organizational directory and personal Microsoft accounts** — `common` tenant. Works for both AAD work accounts AND personal `@outlook.com` / `@live.com` accounts. **Recommended for most installs.**
    - **Accounts in this organizational directory only** — single-AAD-tenant SSO scenario.
    - **Personal Microsoft accounts only** — `consumers` tenant.
5. **Redirect URI**:
    - Platform: **Web**.
    - URI: `https://your-app.example.com/api/admin/connectors/onedrive/oauth/callback` (HTTPS mandatory — Microsoft rejects `http://` except for `localhost`).
6. Click **Register**. The app's overview page opens.

### 3. Capture the Application (client) ID and Tenant ID

From the overview page, copy:

- **Application (client) ID** → `CONNECTOR_ONEDRIVE_OAUTH_CLIENT_ID`
- **Directory (tenant) ID** → `CONNECTOR_ONEDRIVE_OAUTH_TENANT_ID` *(use `common` instead if you picked "Accounts in any organizational directory and personal Microsoft accounts" at step 2.4)*

### 4. Configure API permissions

1. Left sidebar → **API permissions** → **+ Add a permission** → pick **Microsoft Graph** → **Delegated permissions**.
2. Tick **EXACTLY** these four:
    - `Files.Read`        (read user files)
    - `Files.Read.All`    (read files shared with the user)
    - `User.Read`         (sign in + read profile)
    - `offline_access`    (issue refresh tokens — without this, the connector breaks after ~1h)
3. Click **Add permissions**.
4. If your AAD tenant requires admin consent for these permissions (most enterprise tenants do), click **"Grant admin consent for <tenant>"** at the top of the permission list. Otherwise the connector will see `AADSTS65001` on the first sign-in.

### 5. Generate a client secret

1. Left sidebar → **Certificates & secrets** → **Client secrets** tab → **+ New client secret**.
2. **Description**: `AskMyDocs ingest`.
3. **Expires**: 6 months / 12 months / 24 months — pick what fits your secret-rotation policy.
4. Click **Add**. The secret **Value** column displays the actual secret — **copy it now**, it's never displayed again.
5. Capture: **Value** → `CONNECTOR_ONEDRIVE_OAUTH_CLIENT_SECRET`. *(The "Secret ID" column is the GUID identifier of the secret, NOT the secret value — ignore it.)*

### 6. Write credentials to `.env`

In your AskMyDocs host app's `.env`:

```dotenv
CONNECTOR_ONEDRIVE_OAUTH_CLIENT_ID=<application-client-id>
CONNECTOR_ONEDRIVE_OAUTH_CLIENT_SECRET=<client-secret-value>
CONNECTOR_ONEDRIVE_OAUTH_REDIRECT_URI=https://your-app.example.com/api/admin/connectors/onedrive/oauth/callback
# `common` for both work + personal, `consumers` for personal only, or a specific tenant GUID for enterprise SSO:
CONNECTOR_ONEDRIVE_OAUTH_TENANT_ID=common
```

If you're testing OAuth locally and don't have a publicly-routable HTTPS redirect URI, use a tunnel (Cloudflare Tunnel, ngrok, Tailscale Funnel) so Microsoft can call your callback.

### 7. Verify (curl)

```bash
# Replace <token> with an access token obtained via the OAuth flow.
curl -s https://graph.microsoft.com/v1.0/me \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json"
```

Expected: `200 OK` with a JSON user payload. If you see `401 InvalidAuthenticationToken` → token expired (1h lifetime); the connector refreshes automatically. If you see `403 Forbidden` → permission missing; re-check step 4.

### 8. Common errors

- `AADSTS65001 — The user or administrator has not consented` — Admin consent missing on the API permissions. Click **"Grant admin consent"** at step 4.4.
- `AADSTS9002326 — Cross-origin token redemption is permitted only for the 'Single-Page Application' client-type` — Your app was registered as SPA instead of Web. Re-register at step 2 and pick **Web** platform.
- `invalid_client AADSTS7000215` — Client secret value typed wrong, or secret expired. Regenerate at step 5.
- Redirect URI mismatch — The URI in `.env` must match what's registered in Azure char-for-char (trailing slash, scheme, hostname).

## Activation inside AskMyDocs

After `composer require` + the env vars above:

1. Run the host app's admin UI.
2. Navigate to **Settings → Connectors**.
3. The **Microsoft OneDrive** card appears with an **Install** button.
4. Click **Install** → browser redirects to `login.microsoftonline.com` → operator authorises → returns to the admin UI → status flips to `active`.
5. The first full sync fires within the cadence window. To trigger immediately, click **Sync now**.

## What gets ingested

For every supported file the integration can see:

- **File body** — downloaded via `/me/drive/items/{id}/content`. Textual blobs (mime starts with `text/`) are PII-redacted at the ingest boundary; binary blobs (PDF) are handed off as-is.
- **Frontmatter / metadata** captured under `metadata.converter_hints.onedrive`:
  - `item_id`, `mime_type`, `web_url`, `size`
  - `folder_path` (the `parentReference.path` from Graph)
  - `owner` (createdBy email or displayName fallback)
  - `last_modified`, `last_modified_by`
  - `shared_with` — list of emails the file is shared with
- **`_derived` reranker signals** under `metadata.converter_hints._derived`:
  - `recency_bucket`, `owner`

The synthetic MIME `application/vnd.onedrive.office+json` routes Office formats to the host's OneDrive-aware chunker when one is installed; everything else preserves its native MIME (`text/markdown`, `text/plain`, `application/pdf`).

## Sync semantics

- **Full sync** — recursive `walkFolder` from `/me/drive/root/children`. Depth cap at 8 levels. Each MIME-supported file is downloaded + dispatched. At the end, `/me/drive/root/delta?token=latest` is called to capture the initial delta cursor for the next incremental run.
- **Incremental sync** — uses the persisted `delta_link` cursor against `/me/drive/root/delta`. Returns ONLY items that changed since the cursor (or were deleted). Items with the `deleted` facet route through `softDeleteByRemoteId('onedrive_item_id', ...)`. The fresh `@odata.deltaLink` from the response is persisted as the cursor for the next run.
- **Disconnect** — best-effort POST to `/me/revokeSignInSessions` (Microsoft's documented "sign out from all sessions" endpoint), then local credentials are cleared. Errors from the revoke call are logged but never propagated.

## Supported MIME types

Today the connector downloads + dispatches files of these MIME types:

- `text/markdown`
- `text/plain`
- `application/pdf`

Office formats (`.docx`, `.xlsx`, `.pptx`) and Outlook items (`.eml`) are filtered out at the listing stage. Future versions will surface them once host ingest pipelines ship dedicated extractors — the `VendorMimeSelector::forOneDrive()` helper already maps the synthetic `application/vnd.onedrive.office+json` MIME for when that lands.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite has two flavours:

| Suite     | What it covers                                                                                  | Network |
|-----------|-------------------------------------------------------------------------------------------------|---------|
| Feature   | `MicrosoftGraphPaginator` + `OneDriveConnector` against `Http::fake()` and the spy ingestion contract — pagination, delta cursor, deletion routing, OAuth refresh, MIME filtering. | None    |
| Live      | Opt-in — actually hits graph.microsoft.com. Skipped unless `CONNECTOR_ONEDRIVE_LIVE=1`.          | Real    |

CI runs Default (Feature) against PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

## Live testsuite

```bash
export CONNECTOR_ONEDRIVE_LIVE=1
export CONNECTOR_ONEDRIVE_TOKEN=<a-real-access-token-from-the-oauth-flow>
vendor/bin/phpunit --testsuite=Live
```

This calls `/v1.0/me` once to validate credentials.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `AADSTS65001 user has not consented` | Admin consent missing on API permissions | Click "Grant admin consent for <tenant>" in Azure portal → App registrations → your app → API permissions |
| Tokens never refresh after install | Missing `offline_access` scope OR `prompt=consent` skipped | The connector forces `prompt=consent` to guarantee a refresh token is issued. If you registered without `offline_access`, re-tick it at API permissions then re-install |
| `403 Forbidden` on `/me/drive/items/.../content` | File requires SharePoint-tenant-wide permission instead of just OneDrive `Files.Read` | Add `Sites.Read.All` to your AAD app's permissions for cross-site files |
| Incremental sync still does full walks | No delta cursor was persisted on the previous full-sync run | Trigger another full sync; the cursor is captured at the end via `/me/drive/root/delta?token=latest` |
| Deletions not removed from KB | The host has not configured `softDeleteByRemoteId` to look up by `onedrive_item_id` | Verify the host's `ConnectorIngestionContract` impl recognises the `onedrive_item_id` metadata key (AskMyDocs does out of the box) |
| Office files don't appear in search | `.docx` / `.xlsx` / `.pptx` are filtered out at the listing stage today | Wait for the host's docx extractor, or pre-convert to PDF in OneDrive |

## License

Apache-2.0 — see [LICENSE](LICENSE).

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.
