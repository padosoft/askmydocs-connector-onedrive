<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorOneDrive\Tests\Live;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorOneDrive\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Live test — hits graph.microsoft.com when `CONNECTOR_ONEDRIVE_LIVE=1`
 * and a valid `CONNECTOR_ONEDRIVE_TOKEN` is present in the environment.
 *
 * Operators run this manually to validate credentials. CI does NOT run
 * this suite by default.
 */
final class OneDriveLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONNECTOR_ONEDRIVE_LIVE') !== '1') {
            $this->markTestSkipped('CONNECTOR_ONEDRIVE_LIVE not set to 1 — live suite disabled.');
        }

        $token = getenv('CONNECTOR_ONEDRIVE_TOKEN');
        if ($token === false || trim((string) $token) === '') {
            $this->markTestSkipped('Missing credential env var: CONNECTOR_ONEDRIVE_TOKEN');
        }
    }

    #[Test]
    public function fetches_authenticated_user_via_real_graph(): void
    {
        $response = Http::withToken((string) getenv('CONNECTOR_ONEDRIVE_TOKEN'))
            ->acceptJson()
            ->timeout(10)
            ->get('https://graph.microsoft.com/v1.0/me');

        $this->assertTrue(
            $response->successful(),
            'Microsoft Graph /me returned: '.$response->status(),
        );
    }
}
