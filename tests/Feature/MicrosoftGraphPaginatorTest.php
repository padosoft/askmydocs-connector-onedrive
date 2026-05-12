<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorOneDrive\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorPaginationLimitException;
use Padosoft\AskMyDocsConnectorOneDrive\Support\MicrosoftGraphPaginator;
use Padosoft\AskMyDocsConnectorOneDrive\Tests\TestCase;

/**
 * Validates the @odata.nextLink / @odata.deltaLink walker semantics
 * against Graph-shaped payloads.
 */
final class MicrosoftGraphPaginatorTest extends TestCase
{
    public function test_walk_terminates_on_missing_next_link(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::sequence()
                ->push([
                    'value' => [['id' => '1'], ['id' => '2']],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/next',
                ], 200)
                ->push([
                    'value' => [['id' => '3']],
                ], 200),
        ]);

        $items = (new MicrosoftGraphPaginator)->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));

        $this->assertCount(3, $items);
        $this->assertSame('1', $items[0]['id']);
        $this->assertSame('3', $items[2]['id']);
    }

    public function test_walk_handles_single_page_response(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response([
                'value' => [['id' => 'only']],
            ], 200),
        ]);

        $items = (new MicrosoftGraphPaginator)->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));

        $this->assertCount(1, $items);
    }

    public function test_walk_handles_empty_value_array(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response(['value' => []], 200),
        ]);

        $items = (new MicrosoftGraphPaginator)->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));

        $this->assertSame([], $items);
    }

    public function test_walk_throws_connector_auth_exception_on_401(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response(['error' => ['message' => 'invalid token']], 401),
        ]);

        $this->expectException(ConnectorAuthException::class);

        (new MicrosoftGraphPaginator)->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));
    }

    public function test_walk_throws_connector_auth_exception_on_403(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response(['error' => ['message' => 'forbidden']], 403),
        ]);

        $this->expectException(ConnectorAuthException::class);

        (new MicrosoftGraphPaginator)->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));
    }

    public function test_walk_throws_connector_api_exception_on_500(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response(['error' => 'oops'], 500),
        ]);

        $this->expectException(ConnectorApiException::class);

        (new MicrosoftGraphPaginator)->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));
    }

    public function test_walk_throws_connector_api_exception_on_429(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $this->expectException(ConnectorApiException::class);

        (new MicrosoftGraphPaginator)->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));
    }

    public function test_walk_lazy_yields_one_batch_at_a_time(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::sequence()
                ->push([
                    'value' => [['id' => 'a'], ['id' => 'b']],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/page2',
                ], 200)
                ->push([
                    'value' => [['id' => 'c']],
                ], 200),
        ]);

        $batches = [];
        foreach ((new MicrosoftGraphPaginator)->walkLazy(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        )) as $batch) {
            $batches[] = array_map(static fn ($r) => $r['id'], $batch);
        }

        $this->assertSame([['a', 'b'], ['c']], $batches);
    }

    public function test_walk_lazy_allows_early_break(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::sequence()
                ->push([
                    'value' => [['id' => 'fresh']],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/page2',
                ], 200)
                ->push([
                    'value' => [['id' => 'should-not-fetch']],
                ], 200),
        ]);

        $seen = [];
        foreach ((new MicrosoftGraphPaginator)->walkLazy(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        )) as $batch) {
            foreach ($batch as $row) {
                $seen[] = $row['id'];
            }
            break;
        }

        $this->assertSame(['fresh'], $seen);
        Http::assertSentCount(1);
    }

    public function test_walk_captures_odata_deltalink_sidechannel(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response([
                'value' => [['id' => 'x']],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=abc',
            ], 200),
        ]);

        $paginator = new MicrosoftGraphPaginator;
        $paginator->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/delta',
        ));

        $this->assertSame(
            'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=abc',
            $paginator->deltaLink(),
        );
    }

    public function test_walk_throws_pagination_limit_exception_at_max_pages(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response([
                'value' => [['id' => 'always-more']],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/never-ending',
            ], 200),
        ]);

        $this->expectException(ConnectorPaginationLimitException::class);

        (new MicrosoftGraphPaginator)->walk(
            fn (?string $nextLink) => Http::get($nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children'),
            maxPages: 2,
        );
    }

    public function test_pagination_limit_exception_exposes_max_pages(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response([
                'value' => [['id' => 'partial']],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/next',
            ], 200),
        ]);

        try {
            (new MicrosoftGraphPaginator)->walk(
                fn (?string $nextLink) => Http::get($nextLink ?? 'https://graph.microsoft.com/v1.0/start'),
                maxPages: 2,
            );
            $this->fail('Expected ConnectorPaginationLimitException');
        } catch (ConnectorPaginationLimitException $e) {
            $this->assertSame(2, $e->maxPages);
        }
    }

    public function test_walk_throws_api_exception_on_non_json_body(): void
    {
        Http::fake([
            'graph.microsoft.com/*' => Http::response('not json at all', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->expectException(ConnectorApiException::class);

        (new MicrosoftGraphPaginator)->walk(fn (?string $nextLink) => Http::get(
            $nextLink ?? 'https://graph.microsoft.com/v1.0/me/drive/root/children',
        ));
    }
}
