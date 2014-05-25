<?php
namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Indexer;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\Node;
use TYPO3\TYPO3CR\Domain\Model\NodeData;
use TYPO3\TYPO3CR\Domain\Service\NodeTypeManager;


/**
 * Indexer for Content Repository Nodes.
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexer extends \TYPO3\TYPO3CR\SearchCommons\Indexer\AbstractNodeIndexer {

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
	 * the default context variables available inside Eel
	 *
	 * @var array
	 */
	protected $defaultContextVariables;

	/**
	 * @var array
	 */
	protected $fulltextRootNodeTypes = array();

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
	 * @param null $targetWorkspace
	 * @return void
	 */
	public function indexNode(Node $node, $targetWorkspace = NULL) {
		$identifier = $this->generateUniqueNodeIdentifier($node);

		if ($node->isRemoved()) {
			$this->indexClient->removeData($identifier);
			return;
		}

		$fulltextData = array();

		$nodePropertiesToBeStoredInIndex = $this->extractPropertiesAndFulltext($node, $fulltextData);
		if (!(count($fulltextData) === 0 || (count($fulltextData) === 1 && $fulltextData['text'] === ''))) {
			$this->addFulltextToRoot($node, $fulltextData);
		}

		$this->indexClient->indexData($identifier, $nodePropertiesToBeStoredInIndex, $fulltextData);
	}

	/**
	 * @param Node $nodeData
	 * @return void
	 */
	public function removeNode(Node $nodeData) {
		$persistenceObjectIdentifier = $this->persistenceManager->getIdentifierByObject($nodeData);
		$this->indexClient->removeData($persistenceObjectIdentifier);
	}

	/**
	 * @return void
	 */
	public function flush() {
		// no operation, just here to fullfill the interface.
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
	 * @return string
	 */
	protected function generateUniqueNodeIdentifier(Node $node) {
		return md5($node->getContextPath());
	}

}