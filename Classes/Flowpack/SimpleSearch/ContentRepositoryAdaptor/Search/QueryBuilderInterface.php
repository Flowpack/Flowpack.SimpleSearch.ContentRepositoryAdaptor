<?php
namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Search;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;

/**
 * TODO: This should either be in TYPO3CR or a dedicated TYPO3CR.Search.Commons package
 *
 * Query Builder Interface for Content Repository searches
 */
interface QueryBuilderInterface extends \Flowpack\SimpleSearch\Search\QueryBuilderInterface {

	/**
	 * Sets the starting point for this query. Search result should only contain nodes that
	 * match the context of the given node and have it as parent node in their rootline.
	 *
	 * @param NodeInterface $contextNode
	 * @return QueryBuilderInterface
	 */
	public function query(NodeInterface $contextNode);

	/**
	 * Filter by node type, taking inheritance into account.
	 *
	 * @param string $nodeType the node type to filter for
	 * @return QueryBuilderInterface
	 */
	public function nodeType($nodeType);

}