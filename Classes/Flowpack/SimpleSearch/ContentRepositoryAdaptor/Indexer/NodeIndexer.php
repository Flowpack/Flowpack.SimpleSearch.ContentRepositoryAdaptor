<?php
namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Indexer;

use Neos\Flow\Annotations as Flow;
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
	 * @var \Neos\Flow\Persistence\PersistenceManagerInterface
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
	 * @Flow\Inject
	 * @var \Neos\Flow\Security\Context
	 */
	protected $securityContext;

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
	 * @param NodeInterface $node
	 * @param string $targetWorkspaceName
	 * @param boolean $indexVariants
	 * @return void
	 */
	public function indexNode(NodeInterface $node, $targetWorkspaceName = NULL, $indexVariants = TRUE) {
		if ($indexVariants === TRUE) {
			$this->indexAllNodeVariants($node);
			return;
		}

		$identifier = $this->generateUniqueNodeIdentifier($node);

		if ($node->isRemoved()) {
			$this->indexClient->removeData($identifier);
			return;
		}

		$fulltextData = array();

		if (isset($this->indexedNodeData[$identifier])) {
			$properties = $this->indexClient->findOneByIdentifier($identifier);
			$properties['__workspace'] = $properties['__workspace'] . ', #' . ($targetWorkspaceName !== NULL ? $targetWorkspaceName : $node->getContext()->getWorkspaceName() ) . '#';
			$properties['__dimensionshash'] = $properties['__dimensionshash'] . ', #' . md5(json_encode($node->getContext()->getDimensions())) . '#';

			$this->indexClient->insertOrUpdatePropertiesToIndex($properties, $identifier);
		} else {
			$nodePropertiesToBeStoredInIndex = $this->extractPropertiesAndFulltext($node, $fulltextData);
			if (count($fulltextData) !== 0) {
				$this->addFulltextToRoot($node, $fulltextData);
			}

			$this->indexClient->indexData($identifier, $nodePropertiesToBeStoredInIndex, $fulltextData);
			$this->indexedNodeData[$identifier] = $identifier;
		}
	}

	/**
	 * @param NodeInterface $node
	 * @return void
	 */
	public function removeNode(NodeInterface $node) {
		$identifier = $this->generateUniqueNodeIdentifier($node);
		$this->indexClient->removeData($identifier);
	}

	/**
	 * @return void
	 */
	public function flush() {
		$this->indexedNodeData = array();
	}

	/**
	 * @param NodeInterface $node
	 * @return void
	 */
	protected function indexAllNodeVariants(NodeInterface $node) {
		$nodeIdentifier = $node->getIdentifier();

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
		$indexer = $this;
		$this->securityContext->withoutAuthorizationChecks(function() use ($indexer, $nodeIdentifier, $workspaceName) {
			$dimensionCombinations = $indexer->calculateDimensionCombinations();
			if ($dimensionCombinations !== array()) {
				foreach ($dimensionCombinations as $combination) {
					$context = $indexer->contextFactory->create(array('workspaceName' => $workspaceName, 'dimensions' => $combination));
					$node = $context->getNodeByIdentifier($nodeIdentifier);
					if ($node !== NULL) {
						$indexer->indexNode($node, NULL, FALSE);
					}
				}
			} else {
				$context = $indexer->contextFactory->create(array('workspaceName' => $workspaceName));
				$node = $context->getNodeByIdentifier($nodeIdentifier);
				if ($node !== NULL) {
					$indexer->indexNode($node, NULL, FALSE);
				}
			}
		});
	}

	/**
	 * @param NodeInterface $node
	 * @param array $fulltext
	 */
	protected function addFulltextToRoot(NodeInterface $node, $fulltext) {
		$fulltextRoot = $this->findFulltextRoot($node);
		if ($fulltextRoot !== NULL) {
			$identifier = $this->generateUniqueNodeIdentifier($fulltextRoot);
			$this->indexClient->addToFulltext($fulltext, $identifier);
		}
	}

	/**
	 * @param NodeInterface $node
	 * @return NodeInterface
	 */
	protected function findFulltextRoot(NodeInterface $node) {
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
	 * @param NodeInterface $node
	 * @return string
	 */
	protected function generateUniqueNodeIdentifier(NodeInterface $node) {
		$nodeDataPersistenceIdentifier = $this->persistenceManager->getIdentifierByObject($node->getNodeData());
		return $nodeDataPersistenceIdentifier;
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
