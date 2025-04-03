<?php
declare(strict_types=1);

namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Search;

use Flowpack\SimpleSearch\Search\QueryBuilderInterface;
use Neos\Flow\Annotations as Flow;
use Flowpack\SimpleSearch\Search\MysqlQueryBuilder as SimpleSearchMysqlQueryBuilder;

/**
 * MySQL Query Builder for Content Repository searches
 */
class MysqlQueryBuilder extends AbstractQueryBuilder
{
    /**
     * @Flow\Inject
     * @var SimpleSearchMysqlQueryBuilder
     */
    protected $mysqlQueryBuilder;

    protected function getSimpleSearchQueryBuilder(): QueryBuilderInterface
    {
        return $this->mysqlQueryBuilder;
    }

    public function getFindIdentifiersByNodeIdentifierQuery(string $nodeIdentifierPlaceholder): string
    {
        return 'SELECT "__identifier__" FROM "fulltext_objects" WHERE "__identifier" = :' . $nodeIdentifierPlaceholder;
    }

    public function fulltextMatchResult(string $searchword, int $resultTokens = 200, string $ellipsis = '...', string $beginModifier = '<b>', string $endModifier = '</b>'): string
    {
        return $this->mysqlQueryBuilder->fulltextMatchResult($searchword, $resultTokens, $ellipsis, $beginModifier, $endModifier);
    }
}
