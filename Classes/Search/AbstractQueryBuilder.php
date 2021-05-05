<?php
declare(strict_types=1);

namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Search;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Search\Search\QueryBuilderInterface;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Psr\Log\LoggerInterface;

/**
 * Abstract Query Builder for Content Repository searches
 */
abstract class AbstractQueryBuilder implements QueryBuilderInterface, ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * The node inside which searching should happen
     *
     * @var NodeInterface
     */
    protected $contextNode;

    /**
     * Optional message for a log entry on execution of this Query.
     *
     * @var string
     */
    protected $logMessage;

    /**
     * Should this Query be logged?
     *
     * @var boolean
     */
    protected $queryLogEnabled = false;

    abstract protected function getSimpleSearchQueryBuilder(): \Flowpack\SimpleSearch\Search\QueryBuilderInterface;

    public function sortDesc(string $propertyName): QueryBuilderInterface
    {
        $this->getSimpleSearchQueryBuilder()->sortDesc($propertyName);
        return $this;
    }

    public function sortAsc(string $propertyName): QueryBuilderInterface
    {
        $this->getSimpleSearchQueryBuilder()->sortAsc($propertyName);
        return $this;
    }

    public function limit($limit): QueryBuilderInterface
    {
        $this->getSimpleSearchQueryBuilder()->limit($limit);
        return $this;
    }

    public function from($from): QueryBuilderInterface
    {
        $this->getSimpleSearchQueryBuilder()->from($from);
        return $this;
    }

    public function fulltext(string $searchWord, array $options = []): QueryBuilderInterface
    {
        $this->getSimpleSearchQueryBuilder()->fulltext($searchWord);
        return $this;
    }

    /**
     * @param NodeInterface $contextNode
     * @return MysqlQueryBuilder
     * @throws IllegalObjectTypeException
     */
    public function query(NodeInterface $contextNode): QueryBuilderInterface
    {
        $this->getSimpleSearchQueryBuilder()->customCondition("(__parentPath LIKE '%#" . $contextNode->findNodePath() . "#%' OR __path LIKE '" . $contextNode->findNodePath() . "')");
        $this->getSimpleSearchQueryBuilder()->like('__path', (string) $contextNode->findNodePath());
        $this->getSimpleSearchQueryBuilder()->like('__workspace', "#" . $contextNode->getContext()->getWorkspace()->getName() . "#");
        $this->getSimpleSearchQueryBuilder()->like('__dimensionshash', "#" . md5(json_encode($contextNode->getContext()->getDimensions())) . "#");
        $this->contextNode = $contextNode;

        return $this;
    }

    /**
     * @param string $nodeIdentifierPlaceholder
     * @return string
     */
    abstract public function getFindIdentifiersByNodeIdentifierQuery(string $nodeIdentifierPlaceholder);

    /**
     * HIGH-LEVEL API
     */

    /**
     * Filter by node type, taking inheritance into account.
     *
     * @param string $nodeType the node type to filter for
     * @return QueryBuilderInterface
     */
    public function nodeType($nodeType): QueryBuilderInterface
    {
        $this->getSimpleSearchQueryBuilder()->like('__typeAndSuperTypes', "#" . $nodeType . "#");

        return $this;
    }

    /**
     * add an exact-match query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return QueryBuilderInterface
     */
    public function exactMatch($propertyName, $propertyValue): QueryBuilderInterface
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = (string) $propertyValue->getNodeAggregateIdentifier();
        }

        $this->getSimpleSearchQueryBuilder()->exactMatch($propertyName, $propertyValue);
        return $this;
    }

    /**
     * add an like query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return QueryBuilderInterface
     */
    public function like(string $propertyName, $propertyValue): QueryBuilderInterface
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = (string) $propertyValue->getNodeAggregateIdentifier();
        }

        $this->getSimpleSearchQueryBuilder()->like($propertyName, $propertyValue);
        return $this;
    }

    /**
     * add a greater than query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return QueryBuilderInterface
     */
    public function greaterThan($propertyName, $propertyValue)
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = (string) $propertyValue->getNodeAggregateIdentifier();
        }

        $this->getSimpleSearchQueryBuilder()->greaterThan($propertyName, $propertyValue);
        return $this;
    }

    /**
     * add a greater than or equal query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return QueryBuilderInterface
     */
    public function greaterThanOrEqual($propertyName, $propertyValue)
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = (string) $propertyValue->getNodeAggregateIdentifier();
        }

        $this->getSimpleSearchQueryBuilder()->greaterThanOrEqual($propertyName, $propertyValue);
        return $this;
    }

    /**
     * add a less than query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return QueryBuilderInterface
     */
    public function lessThan($propertyName, $propertyValue)
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = (string) $propertyValue->getNodeAggregateIdentifier();
        }

        $this->getSimpleSearchQueryBuilder()->lessThan($propertyName, $propertyValue);
        return $this;
    }

    /**
     * add a less than query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return QueryBuilderInterface
     */
    public function lessThanOrEqual($propertyName, $propertyValue)
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = (string) $propertyValue->getNodeAggregateIdentifier();
        }

        $this->getSimpleSearchQueryBuilder()->lessThanOrEqual($propertyName, $propertyValue);
        return $this;
    }

    /**
     * Execute the query and return the list of nodes as result
     *
     * @return array<\Neos\ContentRepository\Domain\Model\NodeInterface>
     */
    public function execute(): \Traversable
    {
        // Adding implicit sorting by __sortIndex (as last fallback) as we can expect it to be there for nodes.
        $this->getSimpleSearchQueryBuilder()->sortAsc("__sortIndex");

        $timeBefore = microtime(true);
        $result = $this->getSimpleSearchQueryBuilder()->execute();
        $timeAfterwards = microtime(true);

        if ($this->queryLogEnabled === true) {
            $this->logger->debug('Query Log (' . $this->logMessage . '): -- execution time: ' . (($timeAfterwards - $timeBefore) * 1000) . ' ms -- Total Results: ' . count($result));
        }

        $nodes = [];
        foreach ($result as $hit) {
            $nodePath = $hit['__path'];
            $node = $this->contextNode->getNode($nodePath);
            if ($node instanceof NodeInterface) {
                $nodes[(string) $node->getNodeAggregateIdentifier()] = $node;
            }
        }

        return (new \ArrayObject(array_values($nodes)))->getIterator();
    }

    /**
     * Log the current request for debugging after it has been executed.
     *
     * @param string $message an optional message to identify the log entry
     * @return AbstractQueryBuilder
     */
    public function log($message = null)
    {
        $this->queryLogEnabled = true;
        $this->logMessage = $message;

        return $this;
    }

    /**
     * Return the total number of hits for the query.
     *
     * @return integer
     */
    public function count(): int
    {
        $timeBefore = microtime(true);
        $count = $this->getSimpleSearchQueryBuilder()->count();
        $timeAfterwards = microtime(true);

        if ($this->queryLogEnabled === true) {
            $this->logger->debug('Query Log (' . $this->logMessage . '): -- execution time: ' . (($timeAfterwards - $timeBefore) * 1000) . ' ms -- Total Results: ' . $count);
        }

        return $count;
    }

    /**
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        if ($methodName !== 'getFindIdentifiersByNodeIdentifierQuery') {
            // query must be called first to establish a context and starting point.
            return !($this->contextNode === null && $methodName !== 'query');
        }
        return false;
    }
}
