<?php
declare(strict_types=1);

namespace Flowpack\SimpleSearch\ContentRepositoryAdaptor\AssetExtraction;

use Flowpack\SimpleSearch\ContentRepositoryAdaptor\NotImplementedException;
use Neos\ContentRepository\Search\AssetExtraction\AssetExtractorInterface;
use Neos\ContentRepository\Search\Dto\AssetContent;
use Neos\Media\Domain\Model\AssetInterface;

class NullAssetExtractor implements AssetExtractorInterface
{
    /**
     * @throws NotImplementedException
     */
    public function extract(AssetInterface $asset): AssetContent
    {
        throw new NotImplementedException('AssetExtractor is not implemented in SimpleSearchAdaptor.');
    }
}
