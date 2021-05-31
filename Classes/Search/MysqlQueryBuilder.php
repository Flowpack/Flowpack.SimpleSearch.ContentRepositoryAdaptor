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

    /**
     * @param string $nodeIdentifierPlaceholder
     * @return string
     */
    public function getFindIdentifiersByNodeIdentifierQuery(string $nodeIdentifierPlaceholder): string
    {
        return 'SELECT "__identifier__" FROM "fulltext_objects" WHERE "__identifier" = :' . $nodeIdentifierPlaceholder;
    }

    public function fulltextMatchResult($searchword, $resultTokens = 200, $ellipsis = '...', $beginModifier = '<b>', $endModifier = '</b>'): string
    {
        return $this->mysqlQueryBuilder->fulltextMatchResult($searchword, $resultTokens, $ellipsis, $beginModifier, $endModifier);
    }
}
