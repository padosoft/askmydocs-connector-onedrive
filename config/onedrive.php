<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Microsoft OneDrive connector configuration
|--------------------------------------------------------------------------
|
| Provider settings for `padosoft/askmydocs-connector-onedrive`.
|
| The base package merges this block under
| `config('connectors.providers.onedrive')`, so concrete connector code
| reads its config via the standard
| `config('connectors.providers.onedrive.<key>')` path.
|
| OneDrive uses Microsoft identity platform v2.0 — register the app
| in your Azure Active Directory tenant and copy the client id +
| client secret to .env (see the package README §Credential setup).
|
*/

return [
    'client_id' => env('CONNECTOR_ONEDRIVE_OAUTH_CLIENT_ID', env('CONNECTOR_ONEDRIVE_CLIENT_ID')),
    'client_secret' => env('CONNECTOR_ONEDRIVE_OAUTH_CLIENT_SECRET', env('CONNECTOR_ONEDRIVE_CLIENT_SECRET')),
    'redirect_uri' => env(
        'CONNECTOR_ONEDRIVE_OAUTH_REDIRECT_URI',
        env('CONNECTOR_ONEDRIVE_REDIRECT_URI', env('APP_URL', 'http://localhost').'/api/admin/connectors/onedrive/oauth/callback')
    ),

    // Azure AD tenant id — `common` covers both work + personal
    // accounts. Override with a GUID to restrict to a single AAD
    // tenant (enterprise SSO scenario).
    'tenant' => env('CONNECTOR_ONEDRIVE_OAUTH_TENANT_ID', env('CONNECTOR_ONEDRIVE_TENANT', 'common')),

    'oauth_authorize_url' => env('CONNECTOR_ONEDRIVE_OAUTH_AUTHORIZE_URL'),
    'oauth_token_url' => env('CONNECTOR_ONEDRIVE_OAUTH_TOKEN_URL'),

    'api_base' => env('CONNECTOR_ONEDRIVE_API_BASE', 'https://graph.microsoft.com/v1.0'),
];
