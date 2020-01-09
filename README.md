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

