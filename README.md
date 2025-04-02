# SimpleSearch ContentRepositoryAdaptor

[![Latest Stable Version](https://poser.pugx.org/flowpack/simplesearch-contentrepositoryadaptor/v/stable)](https://packagist.org/packages/flowpack/simplesearch-contentrepositoryadaptor) [![Total Downloads](https://poser.pugx.org/flowpack/simplesearch-contentrepositoryadaptor/downloads)](https://packagist.org/packages/flowpack/simplesearch-contentrepositoryadaptor)

A search for the Neos Content Repository based on the SimpleSearch. This package
is an implementation of the Neos.ContentRepository.Search API.


Usage is pretty easy. Install this (and Flowpack.SimpleSearch will follow).

Run the command:

./flow nodeindex:build

After that use the "Search" helper in EEL or the QueryBuilder in PHP to query the
index.

With a few hundred nodes queries should be answered in a few milliseconds max.
My biggest test so far was with around 23000 nodes which still got me reasonable
query times of about 300ms.
If you have more Nodes to index you should probably consider using a "real" search
engine like ElasticSearch.

## Using MySQL


To use MySQL, switch the implementation for the interfaces in your `Objects.yaml`
and configure the DB connection as needed:

    Flowpack\SimpleSearch\Domain\Service\IndexInterface:
      className: 'Flowpack\SimpleSearch\Domain\Service\MysqlIndex'
    
    Neos\ContentRepository\Search\Search\QueryBuilderInterface:
      className: 'Flowpack\SimpleSearch\ContentRepositoryAdaptor\Search\MysqlQueryBuilder'
    
    Flowpack\SimpleSearch\Domain\Service\MysqlIndex:
      arguments:
        1:
          value: 'Neos_CR'
        2:
          value: 'mysql:host=%env:DATABASE_HOST%;dbname=%env:DATABASE_NAME%;charset=utf8mb4'
      properties:
        username:
          value: '%env:DATABASE_USERNAME%'
        password:
          value: '%env:DATABASE_PASSWORD%'

The `arguments` are the index identifier (can be chosen freely) and the DSN.
