<?php
declare(strict_types=1);

namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Command;

use Flowpack\SimpleSearch\ContentRepositoryAdaptor\Indexer\NodeIndexer;
use Flowpack\SimpleSearch\Domain\Service\IndexInterface;
use Flowpack\SimpleSearch\Exception;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Eel\Exception as EelException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;

/**
 * Provides CLI features for index handling
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var NodeIndexer
     */
    protected $nodeIndexer;

    /**
     * @Flow\Inject
     * @var IndexInterface
     */
    protected $indexClient;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Index all nodes.
     *
     * This command (re-)indexes all nodes contained in the content repository and sets the schema beforehand.
     *
     *
     * @param string $workspace
     * @return void
     * @throws Exception
     */
    public function buildCommand(string $contentRepository = 'default', ?string $workspace = null): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        if ($workspace === null) {
            foreach ($contentRepository->findWorkspaces() as $workspaceInstance) {
                $this->indexWorkspace($contentRepositoryId, $workspaceInstance->workspaceName);
            }
        } else {
            $workspaceName = WorkspaceName::fromString($workspace);
            $this->indexWorkspace($contentRepositoryId, $workspaceName);
        }
        $this->outputLine('Finished indexing.');
    }

    /**
     * @param string $workspaceName
     * @throws Exception
     */
    protected function indexWorkspace(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName): void
    {
        $dimensionSpacePoints = $this->nodeIndexer->calculateDimensionCombinations($contentRepositoryId);
        if ($dimensionSpacePoints->isEmpty()) {
            $dimensionSpacePoints = DimensionSpacePointSet::fromArray([DimensionSpacePoint::createWithoutDimensions()]);
        }

        foreach ($dimensionSpacePoints as $dimensionSpacePoint) {
            $indexedNodes = $this->indexWorkspaceInDimension($contentRepositoryId, $workspaceName, $dimensionSpacePoint);
            $this->outputLine('Workspace "' . $workspaceName . '" and dimensions "' . json_encode($dimensionSpacePoint) . '" done. (Indexed ' . $indexedNodes . ' nodes)');
        }
    }

    /**
     * @param Node $currentNode
     * @throws Exception
     */
    protected function indexWorkspaceInDimension(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint): int
    {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $contentGraph = $contentRepository->getContentGraph($workspaceName);

        $rootNodeAggregate = $contentGraph->findRootNodeAggregateByType(NodeTypeNameFactory::forSites());
        $subgraph = $contentGraph->getSubgraph($dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        $rootNode = $subgraph->findNodeById($rootNodeAggregate->nodeAggregateId);
        $indexedNodes = 0;

        $this->nodeIndexer->indexNode($rootNode, null, false);
        $indexedNodes++;

        foreach ($subgraph->findDescendantNodes($rootNode->aggregateId, FindDescendantNodesFilter::create()) as $descendantNode) {
            try {
                $this->nodeIndexer->indexNode($descendantNode, null, false);
                $indexedNodes++;
            } catch (IndexingException|EelException $exception) {
                throw new Exception(sprintf('Error during indexing of node %s', (string)$descendantNode->aggregateId), 1579170291, $exception);
            };
        }

        return $indexedNodes;
    }

    /**
     * Clears the node index from all data.
     *
     * @param boolean $confirmation Should be set to true for something to actually happen.
     */
    public function flushCommand(bool $confirmation = false): void
    {
        if ($confirmation) {
            $this->indexClient->flush();
            $this->outputLine('The node index was flushed.');
        } else {
            $this->outputLine('The node index was NOT flushed, confirmation option was missing.');
        }
    }

    /**
     * Optimize the search index. Depends on the underlaying technology what will happen.
     * For sqlite the VACUUM command is sent to rebuild the full database file.
     */
    public function optimizeCommand(): void
    {
        $this->outputLine('Starting optimization, do not interrupt or your index may be corrupted...');
        $this->indexClient->optimize();
        $this->outputLine('Optimization finished.');
    }

    /**
     * Utility to check the content of the index.
     *
     * @param string $queryString raw SQL to send to the index
     */
    public function findCommand(string $queryString): void
    {
        $result = $this->indexClient->executeStatement($queryString, []);
        $this->output->outputTable($result);
    }
}
