<?php
declare(strict_types=1);

namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Indexer;

use Flowpack\SimpleSearch\Domain\Service\IndexInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\ContentRepository\Search\Indexer\AbstractNodeIndexer;
use Neos\Eel\Exception;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Context;
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

    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

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
     * @param Node $node
     * @param string $targetWorkspaceName
     * @param boolean $indexVariants
     * @return void
     * @throws IndexingException
     * @throws Exception
     */
    public function indexNode(Node $node, $targetWorkspaceName = null, $indexVariants = true): void
    {
        if ($indexVariants === true) {
            $this->indexAllNodeVariants($node);
            return;
        }

        $identifier = $this->generateUniqueNodeIdentifier($node);

        $fulltextData = [];

        if (isset($this->indexedNodeData[$identifier]) && ($properties = $this->indexClient->findOneByIdentifier($identifier)) !== false) {
            unset($properties['__identifier__']);
            $properties['__workspace'] .= ', #' . ($targetWorkspaceName ?? $node->workspaceName) . '#';
            if (array_key_exists('__dimensionshash', $properties)) {
                $properties['__dimensionshash'] .= ', #' . $node->dimensionSpacePoint->hash . '#';
            } else {
                $properties['__dimensionshash'] = '#' . $node->dimensionSpacePoint->hash . '#';
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
     * @param Node $node
     * @param WorkspaceName|null $targetWorkspaceName
     * @return void
     */
    public function removeNode(Node $node, ?WorkspaceName $targetWorkspaceName = null): void
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
     * @param Node $node
     * @return void
     * @throws \Exception
     */
    protected function indexAllNodeVariants(Node $node): void
    {
        $aggregateId = $node->aggregateId;

        $allIndexedVariants = $this->indexClient->executeStatement(
            $this->queryBuilder->getFindIdentifiersByNodeIdentifierQuery('identifier'),
            [':identifier' => $aggregateId->value]
        );
        foreach ($allIndexedVariants as $nodeVariant) {
            $this->indexClient->removeData($nodeVariant['__identifier__']);
        }

        foreach ($this->contentRepositoryRegistry->get($node->contentRepositoryId)->findWorkspaces() as $workspace) {
            $this->indexNodeInWorkspace($node->contentRepositoryId, $aggregateId, $workspace->workspaceName);
        }
    }

    /**
     * @param string $aggregateId
     * @param string $workspaceName
     * @throws \Exception
     */
    protected function indexNodeInWorkspace(ContentRepositoryId $contentRepositoryId, NodeAggregateId $aggregateId, WorkspaceName $workspaceName): void
    {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $indexer = $this;
        $this->securityContext->withoutAuthorizationChecks(static function () use ($indexer, $contentRepository, $aggregateId, $workspaceName) {
            $dimensionSpacePoints = $indexer->calculateDimensionCombinations($contentRepository->id);
            if ($dimensionSpacePoints->isEmpty()) {
                $dimensionSpacePoints = DimensionSpacePointSet::fromArray([DimensionSpacePoint::createWithoutDimensions()]);

            }
            foreach ($dimensionSpacePoints as $dimensionSpacePoint) {
                $subgraph = $contentRepository->getContentSubgraph($workspaceName, $dimensionSpacePoint);

                $node = $subgraph->findNodeById($aggregateId);
                if ($node !== null) {
                    $indexer->indexNode($node, null, false);
                }
            }
        });
    }

    /**
     * @param Node $node
     * @param array $fulltext
     */
    protected function addFulltextToRoot(Node $node, array $fulltext): void
    {
        $fulltextRoot = $this->findFulltextRoot($node);
        if ($fulltextRoot !== null) {
            $identifier = $this->generateUniqueNodeIdentifier($fulltextRoot);
            $this->indexClient->addToFulltext($fulltext, $identifier);
        }
    }

    /**
     * @param Node $node
     * @return Node
     */
    protected function findFulltextRoot(Node $node): ?Node
    {
        $fulltextRootNodeTypeNames = $this->getFulltextRootNodeTypeNames($node->contentRepositoryId);

        if (in_array($node->nodeTypeName, iterator_to_array($fulltextRootNodeTypeNames->getIterator()), true)) {
            return null;
        }

        try {
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
            return $subgraph->findClosestNode($node->aggregateId, FindClosestNodeFilter::create(
                NodeTypeCriteria::createWithAllowedNodeTypeNames($fulltextRootNodeTypeNames)
            ));

        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Generate identifier for index entry based on node identifier and context
     *
     * @param Node $node
     * @return string
     */
    protected function generateUniqueNodeIdentifier(Node $node): string
    {
        return sha1(NodeAddress::fromNode($node)->toJson());
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
    public function calculateDimensionCombinations(ContentRepositoryId $contentRepositoryId): DimensionSpacePointSet
    {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        return $contentRepository->getVariationGraph()->getDimensionSpacePoints();
    }

    /**
     * @param Node $node
     * @return bool
     */
    private function getFulltextRootNodeTypeNames(ContentRepositoryId $contentRepositoryId): NodeTypeNames
    {
        if (!isset($this->fulltextRootNodeTypes[$contentRepositoryId->value])) {
            $nodeTypeNames = [];
            $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
            foreach ($contentRepository->getNodeTypeManager()->getNodeTypes() as $nodeType) {
                $searchSettingsForNodeType = $nodeType->getConfiguration('search');
                if (
                    is_array($searchSettingsForNodeType) && isset($searchSettingsForNodeType['fulltext']['isRoot'])
                    && $searchSettingsForNodeType['fulltext']['isRoot'] === true
                ) {
                    $nodeTypeNames[] = $nodeType->name;
                }
            }
            $this->fulltextRootNodeTypes[$contentRepositoryId->value] = NodeTypeNames::fromArray($nodeTypeNames);
        }

        return $this->fulltextRootNodeTypes[$contentRepositoryId->value];
    }
}
