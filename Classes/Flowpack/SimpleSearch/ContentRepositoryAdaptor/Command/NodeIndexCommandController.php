<?php
namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Command;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;

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
	 * Index all nodes.
	 *
	 * This command (re-)indexes all nodes contained in the content repository and sets the schema beforehand.
	 *
	 * @param integer $limit Amount of nodes to index at maximum
	 * @return void
	 */
	public function buildCommand($limit = NULL) {
		$this->nodeIndexer->setIndexClient($this->indexClient);
		$count = 0;
		foreach ($this->nodeDataRepository->findAll() as $nodeData) {
			if ($limit !== NULL && $count > $limit) {
				break;
			}
			$this->nodeIndexer->indexNode($nodeData);
			$count++;
		}

		$this->outputLine('Done. (indexed ' . $count . ' nodes)');
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