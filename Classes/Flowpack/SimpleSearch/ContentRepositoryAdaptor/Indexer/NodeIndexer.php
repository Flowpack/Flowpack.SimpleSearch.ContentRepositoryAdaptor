<?php
namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Indexer;

use TYPO3\Flow\Annotations as Flow;
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
	 * @return \Flowpack\SimpleSearch\Domain\Service\IndexInterface
	 */
	public function getIndexClient() {
		return $this->indexClient;
	}

	/**
	 * index this node, and add it to the current bulk request.
	 *
	 * @param NodeData $nodeData
	 * @return void
	 */
	public function indexNode(NodeData $nodeData) {
		$persistenceObjectIdentifier = $this->persistenceManager->getIdentifierByObject($nodeData);

		if ($nodeData->isRemoved()) {
			$this->indexClient->removeData($persistenceObjectIdentifier);
			return;
		}

		$fulltextData = array();
		$nodePropertiesToBeStoredInIndex = $this->extractPropertiesAndFulltext($nodeData, $persistenceObjectIdentifier, $fulltextData);

		// Currently the SimpleSearch supports only a single fulltext bucket so everything is thrown in there.
		if (count($fulltextData)) {
			$fulltext = implode("\n" , $fulltextData);
		} else {
			$fulltext = '';
		}

		$this->indexClient->indexData($persistenceObjectIdentifier, $nodePropertiesToBeStoredInIndex, $fulltext);
	}

	/**
	 * @param NodeData $nodeData
	 * @return void
	 */
	public function removeNode(NodeData $nodeData) {
		$persistenceObjectIdentifier = $this->persistenceManager->getIdentifierByObject($nodeData);
		$this->indexClient->removeData($persistenceObjectIdentifier);
	}

	/**
	 * @return void
	 */
	public function flush() {
		// no operation, just here to fullfill the interface.
	}

}