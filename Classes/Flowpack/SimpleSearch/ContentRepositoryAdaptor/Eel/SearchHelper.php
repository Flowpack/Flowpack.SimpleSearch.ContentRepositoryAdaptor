<?php
namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Eel;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;

/**
 * TODO: This should go to the same package than the QueryBuilderInterface
 *
 * Eel Helper to start search queries
 */
class SearchHelper implements \TYPO3\Eel\ProtectedContextAwareInterface {

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Object\ObjectManager
	 */
	protected $objectManager;

	public function query(NodeInterface $contextNode) {
		$queryBuilder = $this->objectManager->get('Flowpack\SimpleSearch\ContentRepositoryAdaptor\Search\QueryBuilderInterface');
		return $queryBuilder->query($contextNode);
	}

	/**
	 * @param string $methodName
	 * @return boolean
	 */
	public function allowsCallOfMethod($methodName) {
		return TRUE;
	}


}