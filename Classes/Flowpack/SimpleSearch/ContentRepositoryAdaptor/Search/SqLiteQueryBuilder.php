<?php
namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Search;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;

/**
 * Query Builder for Content Repository searches
 */
class SqLiteQueryBuilder extends \Flowpack\SimpleSearch\Search\SqLiteQueryBuilder implements \TYPO3\TYPO3CR\Search\Search\QueryBuilderInterface, \TYPO3\Eel\ProtectedContextAwareInterface {

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Log\SystemLoggerInterface
	 */
	protected $logger;

	/**
	 * The node inside which searching should happen
	 *
	 * @var NodeInterface
	 */
	protected $contextNode;

	/**
	 * @var integer
	 */
	protected $from;

	/**
	 * Sorting strings
	 *
	 * @var array<string>
	 */
	protected $sorting = array();

	/**
	 * where clauses
	 *
	 * @var array
	 */
	protected $where = array();

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
	protected $logThisQuery = FALSE;

	/**
	 * @param NodeInterface $contextNode
	 * @return QueryBuilder
	 */
	public function query(NodeInterface $contextNode) {
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
	 * @return QueryBuilder
	 */
	public function nodeType($nodeType) {
		$this->where[] = "(__typeAndSuperTypes LIKE '%#" . $nodeType . "#%')";

		return $this;
	}

	/**
	 * output only $limit records
	 *
	 * Internally, we fetch $limit*$workspaceNestingLevel records, because we fetch the *conjunction* of all workspaces;
	 * and then we filter after execution when we have found the right number of results.
	 *
	 * @param integer $limit
	 * @return QueryBuilder
	 */
	public function limit($limit) {
		if (!$limit) {
			return $this;
		}

		$this->limit = $limit;

		return $this;
	}

	/**
	 * @param integer $from
	 * @return QueryBuilder
	 */
	public function from($from) {
		if (!$from) {
			return $this;
		}

		$this->from = intval($from);

		return $this;
	}

	/**
	 * add an exact-match query for a given property
	 *
	 * @param $propertyName
	 * @param $propertyValue
	 * @return QueryBuilder
	 */
	public function exactMatch($propertyName, $propertyValue) {
		if ($propertyValue instanceof NodeInterface) {
			$propertyValue = $propertyValue->getIdentifier();
		}

		return parent::exactMatch($propertyName, $propertyValue);
	}

	/**
	 * add an like query for a given property
	 *
	 * @param $propertyName
	 * @param $propertyValue
	 * @return QueryBuilder
	 */
	public function like($propertyName, $propertyValue) {
		if ($propertyValue instanceof NodeInterface) {
			$propertyValue = $propertyValue->getIdentifier();
		}

		return parent::like($propertyName, $propertyValue);
	}

	/**
	 * Execute the query and return the list of nodes as result
	 *
	 * @return array<\TYPO3\TYPO3CR\Domain\Model\NodeInterface>
	 */
	public function execute() {
		$timeBefore = microtime(TRUE);
		$query = $this->buildQueryString();
		$result = $this->indexClient->query($query);
		$timeAfterwards = microtime(TRUE);

		if ($this->logThisQuery === TRUE) {
			$this->logger->log('Query Log (' . $this->logMessage . '): ' . $query . ' -- execution time: ' . (($timeAfterwards - $timeBefore) * 1000) . ' ms -- Total Results: ' . count($result), LOG_DEBUG);
		}

		if (empty($result)) {
			return array();
		}

		$nodes = array();
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
	 * @return $this
	 */
	public function log($message = NULL) {
		$this->logThisQuery = TRUE;
		$this->logMessage = $message;

		return $this;
	}

	/**
	 * Return the total number of hits for the query.
	 *
	 * @return integer
	 */
	public function count() {
		$timeBefore = microtime(TRUE);
		$query = $this->buildQueryString();
		$result = $this->indexClient->query($query);
		$timeAfterwards = microtime(TRUE);

		$count = count($result);

		if ($this->logThisQuery === TRUE) {
			$this->logger->log('Query Log (' . $this->logMessage . '): ' . $query . ' -- execution time: ' . (($timeAfterwards - $timeBefore) * 1000) . ' ms -- Total Results: ' . $count, LOG_DEBUG);
		}

		return $count;
	}

	/**
	 * All methods are considered safe
	 *
	 * @param string $methodName
	 * @return boolean
	 */
	public function allowsCallOfMethod($methodName) {
		// query must be called first to establish a context and starting point.
		if ($this->contextNode === NULL && $methodName !== 'query') {
			return FALSE;
		}

		return TRUE;
	}

}