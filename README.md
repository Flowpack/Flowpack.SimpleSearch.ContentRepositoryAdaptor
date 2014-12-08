SimpleSearch ContentRepositoryAdaptor
==============================================

a search for the TYPO3 CR based on the SimpleSearch.

Usage is pretty easy. Install this and Flowpack.SimpleSearch.
Run the command:

./flow nodeindex:build

After that use the "Search" helper in EEL or the QueryBuilder in PHP to query the index.

With a few hundred nodes queries should be answered in a few miliseconds max.
My biggest test so far was with around 23000 nodes which still got me resonable query times of about 300ms.
If you have more Nodes to index you should probably consider using a "real" search engine like ElasticSearch.

This package is an implementation of the TYPO3.TYPO3CR.Search API.
