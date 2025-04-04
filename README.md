[![SensioLabs Insight](https://insight.sensiolabs.com/projects/f65658fd-394a-4cd3-8c7b-639680eb4404/small.png)](https://insight.sensiolabs.com/projects/f65658fd-394a-4cd3-8c7b-639680eb4404)
[![Code Climate](https://codeclimate.com/github/kitsunet/Flowpack.SimpleSearch.ContentRepositoryAdaptor/badges/gpa.svg)](https://codeclimate.com/github/kitsunet/Flowpack.SimpleSearch.ContentRepositoryAdaptor)

SimpleSearch ContentRepositoryAdaptor
=====================================

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

Using MySQL
-----------

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

## Exclude NodeTypes from indexing

By default the indexing processes all NodeTypes, but you can change this in your *Settings.yaml*:

```yaml
Neos:
  ContentRepository:
    Search:
      defaultConfigurationPerNodeType:
        '*':
          indexed: true
        'Neos.Neos:FallbackNode':
          indexed: false
        'Neos.Neos:Shortcut':
          indexed: false
        'Neos.Neos:ContentCollection':
          indexed: false
```

You need to explicitly configure the individual NodeTypes (this feature does not check the Super Type configuration).
But you  can use a special notation to configure a full namespace, `Acme.AcmeCom:*` will be applied for all node
types in the `Acme.AcmeCom` namespace. The most specific configuration is used in this order:

- NodeType name (`Neos.Neos:Shortcut`)
- Full namespace notation (`Neos.Neos:*`)
- Catch all (`*`)
