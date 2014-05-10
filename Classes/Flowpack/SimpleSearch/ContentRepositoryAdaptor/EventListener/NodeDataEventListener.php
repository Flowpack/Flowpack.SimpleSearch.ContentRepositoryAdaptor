<?php
namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\EventListener;

/*                                                                                                 *
 * This script belongs to the TYPO3 Flow package "Flowpack.SimpleSearch.ContentRepositoryAdaptor". *
 *                                                                                                 *
 * It is free software; you can redistribute it and/or modify it under                             *
 * the terms of the GNU General Public License, either version 3 of the                            *
 * License, or (at your option) any later version.                                                 *
 *                                                                                                 *
 * The TYPO3 project - inspiring people to share!                                                  *
 *                                                                                                 */

use Doctrine\ORM\Event\LifecycleEventArgs;
use Flowpack\SimpleSearch\ContentRepositoryAdaptor\Indexer\NodeIndexingManager;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeData;


/**
 * Doctrine event listener
 *
 * @Flow\Scope("singleton")
 */
class NodeDataEventListener {

	/**
	 * @Flow\Inject
	 * @var NodeIndexingManager
	 */
	protected $nodeIndexingManager;

	/**
	 * @param LifecycleEventArgs $eventArgs
	 * @return void
	 */
	public function prePersist(LifecycleEventArgs $eventArgs) {
		$entity = $eventArgs->getEntity();
		if ($entity instanceof NodeData) {
			$this->nodeIndexingManager->indexNode($entity);
		}
	}

	/**
	 * @param LifecycleEventArgs $eventArgs
	 * @return void
	 */
	public function preUpdate(LifecycleEventArgs $eventArgs) {
		$entity = $eventArgs->getEntity();
		if ($entity instanceof NodeData) {
			$this->nodeIndexingManager->indexNode($entity);
		}
	}

	/**
	 * @param LifecycleEventArgs $eventArgs
	 * @return void
	 */
	public function preRemove(LifecycleEventArgs $eventArgs) {
		$entity = $eventArgs->getEntity();
		if ($entity instanceof NodeData) {
			$this->nodeIndexingManager->removeNode($entity);
		}
	}

	public function postFlush() {
		$this->nodeIndexingManager->flushQueues();
	}

}
