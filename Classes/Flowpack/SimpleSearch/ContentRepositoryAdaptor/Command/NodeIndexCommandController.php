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
		foreach ($this->calculateDimensionCombinations() as $combination) {
			$context = $this->contextFactory->create(array('workspace' => $workspaceName, 'dimensions' => $combination));
			$rootNode = $context->getRootNode();

			$this->traverseNodes($rootNode);
			$this->outputLine('Workspace "' . $workspaceName . '" and dimensions "' . json_encode($combination) . '" done. (Indexed ' . $this->indexedNodes . ' nodes)');
			$this->indexedNodes = 0;
		}
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
	 * @return array
	 */
	protected function calculateDimensionCombinations() {
		$dimensionPresets = $this->contentDimensionPresetSource->getAllPresets();

		$dimensionValueCountByDimension = array();
		$possibleCombinationCount = 1;
		$combinations = array();

		foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
			if (isset($dimensionPreset['presets']) && !empty($dimensionPreset['presets'])) {
				$dimensionValueCountByDimension[$dimensionName] = count($dimensionPreset['presets']);
				$possibleCombinationCount = $possibleCombinationCount * $dimensionValueCountByDimension[$dimensionName];
			}
		}

		foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
			for ($i = 0; $i < $possibleCombinationCount; $i++) {
				if (!isset($combinations[$i]) || !is_array($combinations[$i])) {
					$combinations[$i] = array();
				}

				$currentDimensionCurrentPreset = current($dimensionPresets[$dimensionName]['presets']);
				$combinations[$i][$dimensionName] = $currentDimensionCurrentPreset['values'];

				if (!next($dimensionPresets[$dimensionName]['presets'])) {
					reset($dimensionPresets[$dimensionName]['presets']);
				}
			}
		}

		return $combinations;
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