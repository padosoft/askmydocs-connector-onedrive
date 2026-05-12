# Microsoft OneDrive credential setup

The canonical walkthrough lives in [`README.md` §Credential setup](../README.md#credential-setup-junior-proof-step-by-step).

## Cheatsheet

1. Open <https://portal.azure.com> → **App registrations** → **New registration**.
2. Pick **Web** platform + redirect URI: `https://your-app/api/admin/connectors/onedrive/oauth/callback`.
3. Pick supported account types — `common` for both work + personal, or a specific tenant.
4. Copy **Application (client) ID** + **Directory (tenant) ID**.
5. **API permissions** (Microsoft Graph, Delegated):
    - `Files.Read`
    - `Files.Read.All`
    - `User.Read`
    - `offline_access`
6. Click **Grant admin consent** if your tenant requires it.
7. **Certificates & secrets** → **New client secret** → copy the **Value** (not Secret ID).
8. Write to `.env`:
    - `CONNECTOR_ONEDRIVE_OAUTH_CLIENT_ID=<application-client-id>`
    - `CONNECTOR_ONEDRIVE_OAUTH_CLIENT_SECRET=<client-secret-value>`
    - `CONNECTOR_ONEDRIVE_OAUTH_REDIRECT_URI=https://your-app/api/admin/connectors/onedrive/oauth/callback`
    - `CONNECTOR_ONEDRIVE_OAUTH_TENANT_ID=common`
9. Install via AskMyDocs admin UI → Settings → Connectors → Microsoft OneDrive → Install.

⚠ **Don't skip `offline_access`** — without it the connector breaks after ~1h (no refresh token issued).
⚠ **The CLIENT SECRET VALUE displays once** — copy it immediately. The Secret ID column is the GUID identifier and is NOT the secret.
