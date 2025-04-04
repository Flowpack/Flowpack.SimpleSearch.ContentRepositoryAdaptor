<?php
declare(strict_types=1);

namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Indexer;

use Flowpack\SimpleSearch\ContentRepositoryAdaptor\Service\NodeTypeIndexingConfiguration;
use Flowpack\SimpleSearch\Domain\Service\IndexInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\ContentRepository\Search\Indexer\AbstractNodeIndexer;
use Neos\Eel\Exception;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Context;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Indexer for Content Repository Nodes.
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexer extends AbstractNodeIndexer
{
    /**
     * @Flow\Inject
     * @var IndexInterface
     */
    protected $indexClient;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Search\Search\QueryBuilderInterface
     */
    protected $queryBuilder;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @var array
     */
    protected $fulltextRootNodeTypes = [];

    /**
     * @var array
     */
    protected $indexedNodeData = [];

    /**
     * @Flow\Inject
     * @var NodeTypeIndexingConfiguration
     */
    protected $nodeTypeIndexingConfiguration;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Called by the Flow object framework after creating the object and resolving all dependencies.
     *
     * @param integer $cause Creation cause
     * @throws \Neos\Flow\Configuration\Exception\InvalidConfigurationTypeException
     */
    public function initializeObject($cause)
    {
        parent::initializeObject($cause);
        foreach ($this->nodeTypeManager->getNodeTypes() as $nodeType) {
            $searchSettingsForNodeType = $nodeType->getConfiguration('search');
            if (is_array($searchSettingsForNodeType) && isset($searchSettingsForNodeType['fulltext']['isRoot']) && $searchSettingsForNodeType['fulltext']['isRoot'] === true) {
                $this->fulltextRootNodeTypes[] = $nodeType->getName();
            }
        }
    }

    /**
     * @return IndexInterface
     */
    public function getIndexClient(): IndexInterface
    {
        return $this->indexClient;
    }

    /**
     * index this node, and add it to the current bulk request.
     *
     * @param NodeInterface $node
     * @param string $targetWorkspaceName
     * @param boolean $indexVariants
     * @return void
     * @throws NodeException
     * @throws IndexingException
     * @throws Exception
     */
    public function indexNode(NodeInterface $node, $targetWorkspaceName = null, $indexVariants = true): void
    {
        if ($this->nodeTypeIndexingConfiguration->isIndexable($node->getNodeType()) === false) {
            $this->logger->debug(sprintf('Node "%s" (%s) skipped, Node Type is not allowed in the index.', $node->getContextPath(), $node->getNodeType()), LogEnvironment::fromMethodName(__METHOD__));
            return;
        }

        if ($indexVariants === true) {
            $this->indexAllNodeVariants($node);
            return;
        }

        $identifier = $this->generateUniqueNodeIdentifier($node);

        if ($node->isRemoved()) {
            $this->indexClient->removeData($identifier);
            return;
        }

        $fulltextData = [];

        if (isset($this->indexedNodeData[$identifier]) && ($properties = $this->indexClient->findOneByIdentifier($identifier)) !== false) {
            unset($properties['__identifier__']);
            $properties['__workspace'] .= ', #' . ($targetWorkspaceName ?? $node->getContext()->getWorkspaceName()) . '#';
            if (array_key_exists('__dimensionshash', $properties)) {
                $properties['__dimensionshash'] .= ', #' . md5(json_encode($node->getContext()->getDimensions(), 0, 512)) . '#';
            } else {
                $properties['__dimensionshash'] = '#' . md5(json_encode($node->getContext()->getDimensions(), 0, 512)) . '#';
            }

            $this->indexClient->insertOrUpdatePropertiesToIndex($properties, $identifier);
        } else {
            $nodePropertiesToBeStoredInIndex = $this->extractPropertiesAndFulltext($node, $fulltextData);
            if (count($fulltextData) !== 0) {
                $this->addFulltextToRoot($node, $fulltextData);
            }

            $nodePropertiesToBeStoredInIndex = $this->postProcess($nodePropertiesToBeStoredInIndex);
            $this->indexClient->indexData($identifier, $nodePropertiesToBeStoredInIndex, $fulltextData);
            $this->indexedNodeData[$identifier] = $identifier;
        }
    }

    /**
     * @param NodeInterface $node
     * @return void
     */
    public function removeNode(NodeInterface $node): void
    {
        $identifier = $this->generateUniqueNodeIdentifier($node);
        $this->indexClient->removeData($identifier);
    }

    /**
     * @return void
     */
    public function flush(): void
    {
        $this->indexedNodeData = [];
    }

    /**
     * @param NodeInterface $node
     * @return void
     * @throws \Exception
     */
    protected function indexAllNodeVariants(NodeInterface $node): void
    {
        $nodeIdentifier = (string) $node->getNodeAggregateIdentifier();

        $allIndexedVariants = $this->indexClient->executeStatement(
            $this->queryBuilder->getFindIdentifiersByNodeIdentifierQuery('identifier'),
            [':identifier' => $nodeIdentifier]
        );
        foreach ($allIndexedVariants as $nodeVariant) {
            $this->indexClient->removeData($nodeVariant['__identifier__']);
        }

        foreach ($this->workspaceRepository->findAll() as $workspace) {
            $this->indexNodeInWorkspace($nodeIdentifier, $workspace->getName());
        }
    }

    /**
     * @param string $nodeIdentifier
     * @param string $workspaceName
     * @throws \Exception
     */
    protected function indexNodeInWorkspace(string $nodeIdentifier, string $workspaceName): void
    {
        $indexer = $this;
        $this->securityContext->withoutAuthorizationChecks(static function () use ($indexer, $nodeIdentifier, $workspaceName) {
            $dimensionCombinations = $indexer->calculateDimensionCombinations();
            if ($dimensionCombinations !== []) {
                foreach ($dimensionCombinations as $combination) {
                    $context = $indexer->contextFactory->create(['workspaceName' => $workspaceName, 'dimensions' => $combination]);
                    $node = $context->getNodeByIdentifier($nodeIdentifier);
                    if ($node !== null) {
                        $indexer->indexNode($node, null, false);
                    }
                }
            } else {
                $context = $indexer->contextFactory->create(['workspaceName' => $workspaceName]);
                $node = $context->getNodeByIdentifier($nodeIdentifier);
                if ($node !== null) {
                    $indexer->indexNode($node, null, false);
                }
            }
        });
    }

    /**
     * @param NodeInterface $node
     * @param array $fulltext
     */
    protected function addFulltextToRoot(NodeInterface $node, array $fulltext): void
    {
        $fulltextRoot = $this->findFulltextRoot($node);
        if ($fulltextRoot !== null) {
            $identifier = $this->generateUniqueNodeIdentifier($fulltextRoot);
            $this->indexClient->addToFulltext($fulltext, $identifier);
        }
    }

    /**
     * @param NodeInterface $node
     * @return NodeInterface
     */
    protected function findFulltextRoot(NodeInterface $node): ?NodeInterface
    {
        if (in_array($node->getNodeType()->getName(), $this->fulltextRootNodeTypes, true)) {
            return null;
        }

        try {
            $currentNode = $node->findParentNode();
            while ($currentNode !== null) {
                if (in_array($currentNode->getNodeType()->getName(), $this->fulltextRootNodeTypes, true)) {
                    return $currentNode;
                }

                $currentNode = $currentNode->findParentNode();
            }
        } catch (NodeException $exception) {
            return null;
        }

        return null;
    }

    /**
     * Generate identifier for index entry based on node identifier and context
     *
     * @param NodeInterface $node
     * @return string
     */
    protected function generateUniqueNodeIdentifier(NodeInterface $node): string
    {
        return $this->persistenceManager->getIdentifierByObject($node->getNodeData());
    }

    /**
     * @param array $nodePropertiesToBeStoredInIndex
     * @return array
     */
    protected function postProcess(array $nodePropertiesToBeStoredInIndex): array
    {
        foreach ($nodePropertiesToBeStoredInIndex as $propertyName => $propertyValue) {
            if (is_array($propertyValue)) {
                $nodePropertiesToBeStoredInIndex[$propertyName] = Yaml::dump($propertyValue);
            }
        }

        return $nodePropertiesToBeStoredInIndex;
    }

    /**
     * @return array
     */
    public function calculateDimensionCombinations(): array
    {
        $dimensionPresets = $this->contentDimensionPresetSource->getAllPresets();

        $dimensionValueCountByDimension = [];
        $possibleCombinationCount = 1;
        $combinations = [];

        foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
            if (isset($dimensionPreset['presets']) && !empty($dimensionPreset['presets'])) {
                $dimensionValueCountByDimension[$dimensionName] = count($dimensionPreset['presets']);
                $possibleCombinationCount *= $dimensionValueCountByDimension[$dimensionName];
            }
        }

        foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
            for ($i = 0; $i < $possibleCombinationCount; $i++) {
                if (!isset($combinations[$i]) || !is_array($combinations[$i])) {
                    $combinations[$i] = [];
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
