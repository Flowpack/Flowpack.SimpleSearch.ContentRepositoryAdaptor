<?php
namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Indexer;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\Node;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Service\NodeTypeManager;


/**
 * Indexer for Content Repository Nodes.
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexer extends \TYPO3\TYPO3CR\Search\Indexer\AbstractNodeIndexer {

	/**
	 * @Flow\Inject
	 * @var \Flowpack\SimpleSearch\Domain\Service\IndexInterface
	 */
	protected $indexClient;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
	 */
	protected $persistenceManager;

	/**
	 * @Flow\Inject
	 * @var NodeTypeManager
	 */
	protected $nodeTypeManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\ContentDimensionPresetSourceInterface
	 */
	protected $contentDimensionPresetSource;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository
	 */
	protected $workspaceRepository;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface
	 */
	protected $contextFactory;

	/**
	 * the default context variables available inside Eel
	 *
	 * @var array
	 */
	protected $defaultContextVariables;

	/**
	 * @var array
	 */
	protected $fulltextRootNodeTypes = array();

	protected $indexedNodeData = array();

	/**
	 * Called by the Flow object framework after creating the object and resolving all dependencies.
	 *
	 * @param integer $cause Creation cause
	 */
	public function initializeObject($cause) {
		parent::initializeObject($cause);
		foreach ($this->nodeTypeManager->getNodeTypes() as $nodeType) {
			$searchSettingsForNodeType = $nodeType->getConfiguration('search');
			if (is_array($searchSettingsForNodeType) && isset($searchSettingsForNodeType['fulltext']['isRoot']) && $searchSettingsForNodeType['fulltext']['isRoot'] === TRUE) {
				$this->fulltextRootNodeTypes[] = $nodeType->getName();
			}
		}
	}

	/**
	 * @return \Flowpack\SimpleSearch\Domain\Service\IndexInterface
	 */
	public function getIndexClient() {
		return $this->indexClient;
	}

	/**
	 * index this node, and add it to the current bulk request.
	 *
	 * @param Node $node
	 * @param string $targetWorkspaceName
	 * @param boolean $indexVariants
	 * @return void
	 */
	public function indexNode(Node $node, $targetWorkspaceName = NULL, $indexVariants = TRUE) {
		if ($indexVariants === TRUE) {
			$this->indexAllNodeVariants($node);
			return;
		}
		$nodeDataPersistenceIdentifier = $this->persistenceManager->getIdentifierByObject($node->getNodeData());

		$identifier = $nodeDataPersistenceIdentifier;
		// $this->generateUniqueNodeIdentifier($node, $targetWorkspaceName);

		if ($node->isRemoved()) {
			$this->indexClient->removeData($identifier);
			return;
		}

		$fulltextData = array();

		if (isset($this->indexedNodeData[$nodeDataPersistenceIdentifier])) {
			$resultArray = $this->indexClient->query('SELECT * FROM objects WHERE __identifier__ = "' . $nodeDataPersistenceIdentifier . '" LIMIT 1');
			$properties = $resultArray[0];
			$properties['__workspace'] = $properties['__workspace'] . ', #' . ($targetWorkspaceName !== NULL ? $targetWorkspaceName : $node->getContext()->getWorkspaceName() ) . '#';
			$properties['__dimensionshash'] = $properties['__dimensionshash'] . ', #' . md5(json_encode($node->getContext()->getDimensions())) . '#';

			$this->indexClient->insertOrUpdatePropertiesToIndex($properties, $identifier);
		} else {
			$nodePropertiesToBeStoredInIndex = $this->extractPropertiesAndFulltext($node, $fulltextData);
			if (!(count($fulltextData) === 0 || (count($fulltextData) === 1 && $fulltextData['text'] === ''))) {
				$this->addFulltextToRoot($node, $fulltextData);
			}

			$this->indexClient->indexData($identifier, $nodePropertiesToBeStoredInIndex, $fulltextData);
			$this->indexedNodeData[$nodeDataPersistenceIdentifier] = $nodeDataPersistenceIdentifier;
		}
	}

	/**
	 * @param Node $node
	 * @return void
	 */
	public function removeNode(Node $node) {
		$identifier = $this->generateUniqueNodeIdentifier($node);
		$this->indexClient->removeData($identifier);
	}

	/**
	 * @return void
	 */
	public function flush() {
		// no operation, just here to fullfill the interface.
	}

	/**
	 * @param NodeInterface $node
	 * @return void
	 */
	protected function indexAllNodeVariants(NodeInterface $node) {
		$nodeIdentifier = $node->getIdentifier();

		// FIXME: This is rather ugly, the indexClient or ContentRepositoryAdaptor should have a method to do that.
		$allIndexedVariants = $this->indexClient->query('SELECT __identifier__ FROM objects WHERE __identifier = "' . $nodeIdentifier . '"');
		foreach ($allIndexedVariants as $nodeVariant) {
			$this->indexClient->removeData($nodeVariant['__identifier__']);
		}

		foreach ($this->workspaceRepository->findAll() as $workspace) {
			$this->indexNodeInWorkspace($nodeIdentifier, $workspace->getName());
		}
	}

	/**
	 * @param string $workspaceName
	 */
	protected function indexNodeInWorkspace($nodeIdentifier, $workspaceName) {
		$dimensionCombinations = $this->calculateDimensionCombinations();
		if ($dimensionCombinations !== array()) {
			foreach ($dimensionCombinations as $combination) {
				$context = $this->contextFactory->create(array('workspaceName' => $workspaceName, 'dimensions' => $combination));
				$node = $context->getNodeByIdentifier($nodeIdentifier);
				if ($node !== NULL) {
					$this->indexNode($node, NULL, FALSE);
				}
			}
		} else {
			$context = $this->contextFactory->create(array('workspaceName' => $workspaceName));
			$node = $context->getNodeByIdentifier($nodeIdentifier);
			if ($node !== NULL) {
				$this->indexNode($node, NULL, FALSE);
			}
		}
	}

	/**
	 * @param Node $node
	 * @param array $fulltext
	 */
	protected function addFulltextToRoot(Node $node, $fulltext) {
		$fulltextRoot = $this->findFulltextRoot($node);
		if ($fulltextRoot !== NULL) {
			$identifier = $this->generateUniqueNodeIdentifier($fulltextRoot);
			$this->indexClient->addToFulltext($fulltext, $identifier);
		}
	}

	/**
	 * @param Node $node
	 * @return \TYPO3\TYPO3CR\Domain\Model\NodeInterface
	 */
	protected function findFulltextRoot(Node $node) {
		if (in_array($node->getNodeType()->getName(), $this->fulltextRootNodeTypes)) {
			return NULL;
		}

		$currentNode = $node->getParent();
		while ($currentNode !== NULL) {
			if (in_array($currentNode->getNodeType()->getName(), $this->fulltextRootNodeTypes)) {
				return $currentNode;
			}

			$currentNode = $currentNode->getParent();
		}

		return NULL;
	}

	/**
	 * Generate identifier for index entry based on node identifier and context
	 *
	 * @param Node $node
	 * @param string $targetWorkspaceName
	 * @return string
	 */
	protected function generateUniqueNodeIdentifier(Node $node, $targetWorkspaceName = NULL) {
		$contextPath = $node->getContextPath();
		if ($targetWorkspaceName !== NULL) {
			$contextPath = str_replace($node->getContext()->getWorkspace()->getName(), $targetWorkspaceName, $contextPath);
		}
		return md5($contextPath);
	}

	/**
	 * @return array
	 */
	public function calculateDimensionCombinations() {
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

}