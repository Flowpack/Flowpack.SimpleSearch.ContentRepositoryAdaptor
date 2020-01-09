<?php
declare(strict_types=1);

namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Search;

use Flowpack\SimpleSearch\Search\SqLiteQueryBuilder as SimpleSearchSqLiteQueryBuilder;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Search\Search\QueryBuilderInterface;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Psr\Log\LoggerInterface;

/**
 * Query Builder for Content Repository searches
 *
 * Note: some signatures are not as strict as in the interfaces, because two query builder interfaces are "mixed"
 */
class SqLiteQueryBuilder extends SimpleSearchSqLiteQueryBuilder implements QueryBuilderInterface, ProtectedContextAwareInterface
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

    /**
     * @param NodeInterface $contextNode
     * @return SqLiteQueryBuilder
     * @throws IllegalObjectTypeException
     */
    public function query(NodeInterface $contextNode)
    {
        $this->where[] = "(__parentPath LIKE '%#" . $contextNode->getPath() . "#%' OR __path LIKE '" . $contextNode->getPath() . "')";
        $this->where[] = "(__workspace LIKE '%#" . $contextNode->getContext()->getWorkspace()->getName() . "#%')";
        $this->where[] = "(__dimensionshash LIKE '%#" . md5(json_encode($contextNode->getContext()->getDimensions())) . "#%')";
        $this->contextNode = $contextNode;

        return $this;
    }

    /**
     * HIGH-LEVEL API
     */

    /**
     * Filter by node type, taking inheritance into account.
     *
     * @param string $nodeType the node type to filter for
     * @return SqLiteQueryBuilder
     */
    public function nodeType($nodeType)
    {
        $this->where[] = "(__typeAndSuperTypes LIKE '%#" . $nodeType . "#%')";

        return $this;
    }

    /**
     * add an exact-match query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return \Flowpack\SimpleSearch\Search\QueryBuilderInterface
     */
    public function exactMatch($propertyName, $propertyValue): \Flowpack\SimpleSearch\Search\QueryBuilderInterface
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = $propertyValue->getIdentifier();
        }

        return parent::exactMatch($propertyName, $propertyValue);
    }

    /**
     * add an like query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return \Flowpack\SimpleSearch\Search\QueryBuilderInterface
     */
    public function like(string $propertyName, $propertyValue): \Flowpack\SimpleSearch\Search\QueryBuilderInterface
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = $propertyValue->getIdentifier();
        }

        return parent::like($propertyName, $propertyValue);
    }

    /**
     * add a greater than query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return \Flowpack\SimpleSearch\Search\QueryBuilderInterface
     */
    public function greaterThan(string $propertyName, $propertyValue): \Flowpack\SimpleSearch\Search\QueryBuilderInterface
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = $propertyValue->getIdentifier();
        }

        return parent::greaterThan($propertyName, $propertyValue);
    }

    /**
     * add a greater than or equal query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return \Flowpack\SimpleSearch\Search\QueryBuilderInterface
     */
    public function greaterThanOrEqual(string $propertyName, $propertyValue): \Flowpack\SimpleSearch\Search\QueryBuilderInterface
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = $propertyValue->getIdentifier();
        }

        return parent::greaterThanOrEqual($propertyName, $propertyValue);
    }

    /**
     * add a less than query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return \Flowpack\SimpleSearch\Search\QueryBuilderInterface
     */
    public function lessThan(string $propertyName, $propertyValue): \Flowpack\SimpleSearch\Search\QueryBuilderInterface
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = $propertyValue->getIdentifier();
        }

        return parent::lessThan($propertyName, $propertyValue);
    }

    /**
     * add a less than query for a given property
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @return \Flowpack\SimpleSearch\Search\QueryBuilderInterface
     */
    public function lessThanOrEqual(string $propertyName, $propertyValue): \Flowpack\SimpleSearch\Search\QueryBuilderInterface
    {
        if ($propertyValue instanceof NodeInterface) {
            $propertyValue = $propertyValue->getIdentifier();
        }

        return parent::lessThanOrEqual($propertyName, $propertyValue);
    }

    /**
     * Execute the query and return the list of nodes as result
     *
     * @return array<\Neos\ContentRepository\Domain\Model\NodeInterface>
     */
    public function execute(): array
    {
        // Adding implicit sorting by __sortIndex (as last fallback) as we can expect it to be there for nodes.
        $this->sorting[] = 'objects.__sortIndex ASC';

        $timeBefore = microtime(true);
        $result = parent::execute();
        $timeAfterwards = microtime(true);

        if ($this->queryLogEnabled === true) {
            $this->logger->log('Query Log (' . $this->logMessage . '): -- execution time: ' . (($timeAfterwards - $timeBefore) * 1000) . ' ms -- Total Results: ' . count($result), LOG_DEBUG);
        }

        if (empty($result)) {
            return [];
        }

        $nodes = [];
        foreach ($result as $hit) {
            $nodePath = $hit['__path'];
            $node = $this->contextNode->getNode($nodePath);
            if ($node instanceof NodeInterface) {
                $nodes[$node->getIdentifier()] = $node;
            }
        }

        return array_values($nodes);
    }

    /**
     * Log the current request for debugging after it has been executed.
     *
     * @param string $message an optional message to identify the log entry
     * @return SqLiteQueryBuilder
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
        $count = parent::count();
        $timeAfterwards = microtime(true);

        if ($this->queryLogEnabled === true) {
            $this->logger->log('Query Log (' . $this->logMessage . '): -- execution time: ' . (($timeAfterwards - $timeBefore) * 1000) . ' ms -- Total Results: ' . $count, LOG_DEBUG);
        }

        return $count;
    }

    /**
     * All methods are considered safe
     *
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        // query must be called first to establish a context and starting point.
        return !($this->contextNode === null && $methodName !== 'query');
    }
}
