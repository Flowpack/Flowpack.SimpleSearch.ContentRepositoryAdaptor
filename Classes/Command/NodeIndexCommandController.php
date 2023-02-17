<?php
declare(strict_types=1);

namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\Command;

use Flowpack\SimpleSearch\ContentRepositoryAdaptor\Indexer\NodeIndexer;
use Flowpack\SimpleSearch\Domain\Service\IndexInterface;
use Flowpack\SimpleSearch\Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\Eel\Exception as EelException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;

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

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @var integer
     */
    protected $indexedNodes;

    /**
     * @var array
     * @Flow\InjectConfiguration(package="Neos.ContentRepository.Search")
     */
    protected $settings;

    /**
     * Index all nodes.
     *
     * This command (re-)indexes all nodes contained in the content repository and sets the schema beforehand.
     *
     * @throws Exception
     */
    public function buildCommand(string $workspace = null): void
    {
        if ($workspace === null && $this->settings['indexAllWorkspaces'] === false) {
            $workspace = 'live';
        }

        $this->indexedNodes = 0;
        if ($workspace === null) {
            foreach ($this->workspaceRepository->findAll() as $workspaceInstance) {
                $this->indexWorkspace($workspaceInstance->getName());
            }
        } else {
            $this->indexWorkspace($workspace);
        }
        $this->outputLine('Finished indexing.');
    }

    /**
     * @throws Exception
     */
    protected function indexWorkspace(string $workspaceName): void
    {
        $dimensionCombinations = $this->nodeIndexer->calculateDimensionCombinations();
        if ($dimensionCombinations !== []) {
            foreach ($dimensionCombinations as $combination) {
                $context = $this->contextFactory->create(['workspaceName' => $workspaceName, 'dimensions' => $combination]);
                $rootNode = $context->getRootNode();

                $this->traverseNodes($rootNode);
                $rootNode->getContext()->getFirstLevelNodeCache()->flush();
                $this->outputLine('Workspace "' . $workspaceName . '" and dimensions "' . json_encode($combination) . '" done. (Indexed ' . $this->indexedNodes . ' nodes)');
                $this->indexedNodes = 0;
            }
        } else {
            $context = $this->contextFactory->create(['workspaceName' => $workspaceName]);
            $rootNode = $context->getRootNode();

            $this->traverseNodes($rootNode);
            $this->outputLine('Workspace "' . $workspaceName . '" without dimensions done. (Indexed ' . $this->indexedNodes . ' nodes)');
            $this->indexedNodes = 0;
        }
    }

    /**
     * @throws Exception
     */
    protected function traverseNodes(NodeInterface $currentNode): void
    {
        try {
            $this->nodeIndexer->indexNode($currentNode, null, false);
        } catch (NodeException|IndexingException|EelException $exception) {
            throw new Exception(sprintf('Error during indexing of node %s (%s)', $currentNode->findNodePath(), (string) $currentNode->getNodeAggregateIdentifier()), 1579170291, $exception);
        }
        $this->indexedNodes++;
        foreach ($currentNode->findChildNodes() as $childNode) {
            $this->traverseNodes($childNode);
        }
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
