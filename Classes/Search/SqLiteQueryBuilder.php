<?php
declare(strict_types=1);

namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Search;

use Flowpack\SimpleSearch\Search\QueryBuilderInterface;
use Flowpack\SimpleSearch\Search\SqLiteQueryBuilder as SimpleSearchSqLiteQueryBuilder;
use Neos\Flow\Annotations as Flow;

/**
 * Sqlite Query Builder for Content Repository searches
 *
 */
class SqLiteQueryBuilder extends AbstractQueryBuilder
{
    /**
     * @Flow\Inject
     * @var SimpleSearchSqLiteQueryBuilder
     */
    protected $sqLiteQueryBuilder;

    protected function getSimpleSearchQueryBuilder(): QueryBuilderInterface
    {
        return $this->sqLiteQueryBuilder;
    }

    public function getFindIdentifiersByNodeIdentifierQuery(string $nodeIdentifierPlaceholder): string
    {
        return 'SELECT __identifier__ FROM objects WHERE __identifier = :' . $nodeIdentifierPlaceholder;
    }

    public function fulltextMatchResult(string $searchword, int $resultTokens = 60, string $ellipsis = '...', string $beginModifier = '<b>', string $endModifier = '</b>'): string
    {
        return $this->sqLiteQueryBuilder->fulltextMatchResult($searchword, $resultTokens, $ellipsis, $beginModifier, $endModifier);
    }
}
