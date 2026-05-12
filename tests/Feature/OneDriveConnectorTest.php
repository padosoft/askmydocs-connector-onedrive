<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorOneDrive\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorOneDrive\OneDriveConnector;
use Padosoft\AskMyDocsConnectorOneDrive\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorOneDrive\Tests\TestCase;

/**
 * Feature tests for {@see OneDriveConnector}.
 *
 * Every API interaction is stubbed via `Http::fake()`; host pipeline
 * dispatches go through a spy implementation of
 * {@see ConnectorIngestionContract}.
 */
final class OneDriveConnectorTest extends TestCase
{
    private SpyIngestionContract $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new SpyIngestionContract;
        $this->app->instance(ConnectorIngestionContract::class, $this->spy);
        Storage::fake('local');

        config()->set('connectors.providers.onedrive.client_id', 'cid');
        config()->set('connectors.providers.onedrive.client_secret', 'csec');
        config()->set('connectors.providers.onedrive.redirect_uri', 'http://localhost/cb');
        config()->set('connectors.providers.onedrive.tenant', 'common');
        config()->set('connectors.providers.onedrive.api_base', 'https://graph.microsoft.com/v1.0');
    }

    private function connector(): OneDriveConnector
    {
        return $this->app->make(OneDriveConnector::class);
    }

    private function makeInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'onedrive',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function seedActiveCredential(
        int $installationId,
        string $access = 'AT-msgraph',
        ?string $refresh = 'RT-msgraph',
        array $extra = [],
        string $tenantId = 'default',
    ): void {
        ConnectorCredential::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installationId,
            'encrypted_access_token' => Crypt::encryptString($access),
            'encrypted_refresh_token' => $refresh === null ? null : Crypt::encryptString($refresh),
            'expires_at' => Carbon::now()->addHour(),
            'extra_json' => $extra === [] ? null : $extra,
        ]);
    }

    private function initiateAndExtractState(int $installationId): string
    {
        Cache::flush();
        $url = $this->connector()->initiateOAuth($installationId);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['state'] ?? '');
    }

    public function test_initiate_oauth_returns_microsoft_authorize_url_with_state_token(): void
    {
        $installation = $this->makeInstallation();

        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('http://localhost/cb', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertStringContainsString('Files.Read', $query['scope']);
        $this->assertStringContainsString('offline_access', $query['scope']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_oauth_callback_exchanges_code_and_stores_tokens(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'AT-new',
                'refresh_token' => 'RT-new',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'Files.Read User.Read offline_access',
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertSame('AT-new', $vault->getAccessToken($installation->id));
        $this->assertSame('RT-new', $vault->getRefreshToken($installation->id));

        $audits = array_column($this->spy->audits, 'eventType');
        $this->assertContains('installed', $audits);
    }

    public function test_oauth_callback_throws_on_invalid_state(): void
    {
        $installation = $this->makeInstallation();
        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => 'forged']);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_throws_on_missing_code(): void
    {
        $installation = $this->makeInstallation();
        $req = Request::create('/cb', 'GET', ['state' => 'whatever']);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_throws_on_token_exchange_failure(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'bad', 'state' => $state]);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_sync_full_walks_root_children_and_dispatches_supported_files(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/me/drive/root/children')) {
                return Http::response([
                    'value' => [
                        [
                            'id' => 'item-md',
                            'name' => 'readme.md',
                            'file' => ['mimeType' => 'text/markdown'],
                            'webUrl' => 'https://onedrive.live.com/readme.md',
                            'size' => 1024,
                            'lastModifiedDateTime' => '2026-05-01T10:00:00Z',
                            'createdBy' => ['user' => ['email' => 'me@example.com']],
                        ],
                        [
                            'id' => 'item-docx-skipped',
                            'name' => 'report.docx',
                            'file' => ['mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                        ],
                    ],
                ], 200);
            }
            if (str_contains($url, '/me/drive/items/item-md/content')) {
                return Http::response('# Hello', 200, ['Content-Type' => 'text/markdown']);
            }
            if (str_contains($url, '/me/drive/root/delta')) {
                return Http::response([
                    '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=NEXT',
                ], 200);
            }

            return Http::response([], 404);
        });

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->documentsAdded);
        $this->assertCount(1, $this->spy->dispatches);
        $dispatch = $this->spy->dispatches[0];
        $this->assertSame('readme.md', $dispatch['title']);
        $this->assertSame('item-md', $dispatch['metadata']['onedrive_item_id']);
        $this->assertSame('me@example.com', $dispatch['metadata']['converter_hints']['onedrive']['owner']);
    }

    public function test_sync_full_recurses_into_folders(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/me/drive/root/children')) {
                return Http::response([
                    'value' => [
                        ['id' => 'folder-1', 'name' => 'Docs', 'folder' => ['childCount' => 1]],
                    ],
                ], 200);
            }
            if (str_contains($url, '/me/drive/items/folder-1/children')) {
                return Http::response([
                    'value' => [
                        [
                            'id' => 'item-nested',
                            'name' => 'inside.txt',
                            'file' => ['mimeType' => 'text/plain'],
                        ],
                    ],
                ], 200);
            }
            if (str_contains($url, '/me/drive/items/item-nested/content')) {
                return Http::response('plain text body', 200);
            }
            if (str_contains($url, '/me/drive/root/delta')) {
                return Http::response(['@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?token=NEXT'], 200);
            }

            return Http::response([], 404);
        });

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(1, $result->documentsAdded);
        $this->assertSame('inside.txt', $this->spy->dispatches[0]['title']);
    }

    public function test_sync_full_persists_delta_link_for_next_incremental(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/me/drive/root/children')) {
                return Http::response(['value' => []], 200);
            }
            if (str_contains($url, '/me/drive/root/delta')) {
                return Http::response([
                    '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=ABC',
                ], 200);
            }

            return Http::response([], 404);
        });

        $this->connector()->syncFull($installation->id);

        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=ABC',
            $vault->getExtraKey($installation->id, 'delta_link'),
        );
    }

    public function test_sync_incremental_falls_back_to_full_when_no_delta_cursor(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/me/drive/root/children')) {
                return Http::response(['value' => []], 200);
            }
            if (str_contains($url, '/me/drive/root/delta')) {
                return Http::response(['@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/d'], 200);
            }

            return Http::response([], 404);
        });

        $result = $this->connector()->syncIncremental($installation->id, null);

        // Full-sync fallback: count is in documentsAdded (0 here)
        $this->assertSame(0, $result->documentsAdded);
    }

    public function test_sync_incremental_processes_delta_and_routes_deletions(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: [
            'delta_link' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=PREV',
        ]);
        $this->spy->remoteIdsThatMatch['deleted-item-id'] = 'default';

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/me/drive/root/delta')) {
                return Http::response([
                    'value' => [
                        // Deletion event.
                        [
                            'id' => 'deleted-item-id',
                            'deleted' => ['state' => 'deleted'],
                        ],
                        // Updated file.
                        [
                            'id' => 'fresh-item',
                            'name' => 'doc.md',
                            'file' => ['mimeType' => 'text/markdown'],
                            'lastModifiedDateTime' => '2026-05-10T09:00:00Z',
                        ],
                    ],
                    '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=NEXT',
                ], 200);
            }
            if (str_contains($url, '/me/drive/items/fresh-item/content')) {
                return Http::response('# Fresh body', 200, ['Content-Type' => 'text/markdown']);
            }

            return Http::response([], 404);
        });

        $result = $this->connector()->syncIncremental(
            $installation->id,
            Carbon::parse('2026-05-09T00:00:00Z'),
        );

        $this->assertSame(1, $result->documentsUpdated);
        $this->assertSame(1, $result->documentsRemoved);
        $this->assertSame('onedrive_item_id', $this->spy->deletions[0]['metadata_key']);
        $this->assertSame('deleted-item-id', $this->spy->deletions[0]['remote_id']);

        // New delta link persisted.
        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=NEXT',
            $vault->getExtraKey($installation->id, 'delta_link'),
        );
    }

    public function test_disconnect_calls_revoke_sessions_and_clears_credentials(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake();

        $this->connector()->disconnect($installation->id);

        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/me/revokeSignInSessions'));
        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_health_returns_healthy_when_me_succeeds(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me' => Http::response(['id' => '1'], 200),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $status->state);
    }

    public function test_health_returns_errored_without_credentials(): void
    {
        $installation = $this->makeInstallation();
        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_health_returns_errored_on_401(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me' => Http::response(['error' => 'unauth'], 401),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_pii_redaction_applied_for_textual_blobs(): void
    {
        $this->spy->redactionPrefix = '[X] ';
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/me/drive/root/children')) {
                return Http::response([
                    'value' => [
                        [
                            'id' => 'p',
                            'name' => 'pii.md',
                            'file' => ['mimeType' => 'text/markdown'],
                        ],
                    ],
                ], 200);
            }
            if (str_contains($url, '/me/drive/items/p/content')) {
                return Http::response('email user@example.com', 200);
            }
            if (str_contains($url, '/me/drive/root/delta')) {
                return Http::response(['@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/d'], 200);
            }

            return Http::response([], 404);
        });

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk('local');
        $files = $disk->allFiles();
        $contents = (string) $disk->get($files[0]);
        $this->assertStringContainsString('[X]', $contents);
    }

    public function test_refresh_token_replaces_expired_access_token(): void
    {
        $installation = $this->makeInstallation();
        // Seed credential with an EXPIRED access token (vault returns
        // null for getAccessToken when expires_at is in the past).
        ConnectorCredential::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installation->id,
            'encrypted_access_token' => Crypt::encryptString('AT-old'),
            'encrypted_refresh_token' => Crypt::encryptString('RT-old'),
            'expires_at' => Carbon::now()->subHour(),
        ]);

        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'AT-rotated',
                'refresh_token' => 'RT-rotated',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'graph.microsoft.com/v1.0/me' => Http::response(['id' => '1'], 200),
        ]);

        $token = $this->connector()->refreshTokenIfExpired($installation->id);

        $this->assertSame('AT-rotated', $token);
        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertSame('AT-rotated', $vault->getAccessToken($installation->id));
        $this->assertSame('RT-rotated', $vault->getRefreshToken($installation->id));
    }
}
