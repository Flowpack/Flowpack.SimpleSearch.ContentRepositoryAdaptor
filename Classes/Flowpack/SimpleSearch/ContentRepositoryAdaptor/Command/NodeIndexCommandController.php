<?php
namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Command;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;
use TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface;

/**
 * Provides CLI features for index handling
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexCommandController extends CommandController {

	/**
	 * @Flow\Inject
	 * @var \Flowpack\SimpleSearch\ContentRepositoryAdaptor\Indexer\NodeIndexer
	 */
	protected $nodeIndexer;

	/**
	 * @Flow\Inject
	 * @var \Flowpack\SimpleSearch\Domain\Service\IndexInterface
	 */
	protected $indexClient;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
	 */
	protected $nodeDataRepository;

	/**
	 * @Flow\Inject
	 * @var ContextFactoryInterface
	 */
	protected $contextFactory;

	/**
	 * @var integer
	 */
	protected $indexedNodes;


	/**
	 * Index all nodes.
	 *
	 * This command (re-)indexes all nodes contained in the content repository and sets the schema beforehand.
	 *
	 *
	 * @param string $workspace
	 * @param integer $limit Amount of nodes to index at maximum
	 * @return void
	 */
	public function buildCommand($workspace = 'live', $limit = NULL) {
		$this->indexedNodes = 0;
		$context = $this->contextFactory->create(array('workspace' => $workspace));
		$rootNode = $context->getRootNode();

		$this->traverseNodes($rootNode);

		$this->outputLine('Done. (indexed ' . $this->indexedNodes . ' nodes)');
		$this->indexedNodes = 0;
	}

	/**
	 * @param \TYPO3\TYPO3CR\Domain\Model\Node $currentNode
	 */
	protected function traverseNodes(\TYPO3\TYPO3CR\Domain\Model\Node $currentNode) {
		$this->nodeIndexer->indexNode($currentNode);
		$this->indexedNodes++;
		foreach ($currentNode->getChildNodes() as $childNode) {
			$this->traverseNodes($childNode);
		}
	}

	/**
	 * Utility to check the content of the index.
	 *
	 * @param string $queryString
	 */
	public function findCommand($queryString) {
		$result = $this->indexClient->query($queryString);
		foreach ($result as $hit) {
			var_dump($hit);
		}
	}
}