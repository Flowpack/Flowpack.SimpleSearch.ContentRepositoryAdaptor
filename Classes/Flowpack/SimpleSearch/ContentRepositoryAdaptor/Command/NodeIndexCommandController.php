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
	 * @var \TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository
	 */
	protected $workspaceRepository;

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
	 * @Flow\Inject
	 * @var \TYPO3\Neos\Domain\Service\ContentDimensionPresetSourceInterface
	 */
	protected $contentDimensionPresetSource;

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
	 * @return void
	 */
	public function buildCommand($workspace = NULL) {
		$this->indexedNodes = 0;
		if ($workspace === NULL) {
			foreach ($this->workspaceRepository->findAll() as $workspace) {
				$this->indexWorkspace($workspace->getName());
			}
		} else {
			$this->indexWorkspace($workspace->getName());
		}
		$this->outputLine('Finished indexing.');
	}

	/**
	 * @param string $workspaceName
	 */
	protected function indexWorkspace($workspaceName) {
		$dimensionCombinations = $this->nodeIndexer->calculateDimensionCombinations();
		if ($dimensionCombinations !== array()) {
			foreach ($dimensionCombinations as $combination) {
				$context = $this->contextFactory->create(array('workspaceName' => $workspaceName, 'dimensions' => $combination));
				$rootNode = $context->getRootNode();

				$this->traverseNodes($rootNode);
				$this->outputLine('Workspace "' . $workspaceName . '" and dimensions "' . json_encode($combination) . '" done. (Indexed ' . $this->indexedNodes . ' nodes)');
				$this->indexedNodes = 0;
			}
		} else {
			$context = $this->contextFactory->create(array('workspaceName' => $workspaceName));
			$rootNode = $context->getRootNode();

			$this->traverseNodes($rootNode);
			$this->outputLine('Workspace "' . $workspaceName . '" without dimensions done. (Indexed ' . $this->indexedNodes . ' nodes)');
			$this->indexedNodes = 0;
		}
	}

	/**
	 * @param \TYPO3\TYPO3CR\Domain\Model\Node $currentNode
	 */
	protected function traverseNodes(\TYPO3\TYPO3CR\Domain\Model\Node $currentNode) {
		$this->nodeIndexer->indexNode($currentNode, NULL, FALSE);
		$this->indexedNodes++;
		foreach ($currentNode->getChildNodes() as $childNode) {
			$this->traverseNodes($childNode);
		}
	}

	/**
	 * Clears the node index from all data.
	 *
	 * @param boolean $confirmation Should be set to true for something to actually happen.
	 */
	public function flushCommand($confirmation = FALSE) {
		if ($confirmation) {
			$this->indexClient->flush();
			$this->outputLine('The node index was flushed.');
		}
	}

	/**
	 * Optimize the search index. Depends on the underlaying technology what will happen.
	 * For sqlite the VACUUM command is sent to rebuild the full database file.
	 */
	public function optimizeCommand() {
		$this->outputLine('Starting optimization, do not interrupt or your index may be corrupted...');
		$this->indexClient->optimize();
		$this->outputLine('Optimization finished.');
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