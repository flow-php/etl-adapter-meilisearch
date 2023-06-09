<?php

declare(strict_types=1);

namespace Flow\ETL\Adapter\Meilisearch\Tests\Integration\MeilisearchPHP;

use Flow\ETL\Adapter\Meilisearch\Tests\Context\MeilisearchContext;
use Flow\ETL\DSL\Meilisearch;
use Flow\ETL\Flow;
use Flow\ETL\Row;
use Flow\ETL\Rows;
use PHPUnit\Framework\TestCase;

final class IntegrationTest extends TestCase
{
    private const DESTINATION_INDEX = 'etl-test-destination-index';

    private const SOURCE_INDEX = 'etl-test-source-index';

    private MeilisearchContext $meilisearchContext;

    protected function setUp() : void
    {
        $this->meilisearchContext = new MeilisearchContext(\getenv('MEILISEARCH_URL'), \getenv('MEILISEARCH_API_KEY'));
        $this->meilisearchContext->deleteIndex(self::SOURCE_INDEX);
        $this->meilisearchContext->createIndex(self::SOURCE_INDEX);
        $this->meilisearchContext->deleteIndex(self::DESTINATION_INDEX);
        $this->meilisearchContext->createIndex(self::DESTINATION_INDEX);
    }

    protected function tearDown() : void
    {
        parent::tearDown();

        $this->meilisearchContext->deleteIndex(self::SOURCE_INDEX);
        $this->meilisearchContext->deleteIndex(self::DESTINATION_INDEX);
    }

    public function test_loading_and_extraction_with_limit_and_transformation() : void
    {
        $this->meilisearchContext->loadRows(
            new Rows(
                ...\array_map(
                    static fn (int $i) : Row => Row::create(
                        new Row\Entry\StringEntry('id', \sha1((string) $i)),
                        new Row\Entry\IntegerEntry('position', $i),
                        new Row\Entry\StringEntry('name', 'id_' . $i),
                        new Row\Entry\BooleanEntry('active', false)
                    ),
                    \range(1, 500)
                ),
            ),
            self::SOURCE_INDEX
        );

        $params = [
            'q' => '',
            'limit' => $limit = 100,
        ];

        $results = (new Flow())
            ->extract(Meilisearch::search($this->meilisearchContext->clientConfig(), $params, self::SOURCE_INDEX))
            ->rows(Meilisearch::hits_to_rows())
            ->limit($limit)
            ->load(
                Meilisearch::bulk_index(
                    $this->meilisearchContext->clientConfig(),
                    self::DESTINATION_INDEX
                )
            )
            ->fetch();

        $this->assertCount($limit, $results);
        $this->assertSame(
            \array_map(
                static fn (int $i) : array => [
                    'id' => \sha1((string) $i),
                    'position' => $i,
                    'name' => 'id_' . $i,
                    'active' => false,
                ],
                \range(1, $limit)
            ),
            $results->toArray()
        );
    }
}
