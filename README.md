# Elasticsearch Backend for GNU social

## Installation

1. Navigate to your `/local/plugins` directory (create it if it doesn't exist)
1. `git clone https://github.com/chimo/gs-elasticsearch.git ElasticSearch`
1. Run `composer install` in the `ElasticSearch` folder to install the dependencies

## Configuration

Tell `/config.php` to use it with (replace `127.0.0.1:9200` with the address/port of your elasticsearch backend server):

```
    $config['elasticsearch']['enabled'] = true;
    $config['elasticsearch']['hosts'] = [ '127.0.0.1:9200' ];
    $config['elasticsearch']['index_name'] = 'gnusocial';
    addPlugin('ElasticSearch');
```

## Usage

You can use the [Lucene query syntax](https://www.elastic.co/guide/en/elasticsearch/reference/5.x/query-dsl-query-string-query.html#query-string-syntax) when searching.

### Searching Notices

Supported fields:

* text: Filters by notice text (default field)
* author: Filters by notice username

The `/search/notice` page searches notice text by default. You can filter by notice author with the `author` field parameter.

For example, the following input will find all notices containing the word "social": `social`

The following input will find all notices containing the word "social" authored by username "gnu": `author:gnu social`

### Searching Profiles

Supported fields:

* nickname (default field)
* fullname
* bio
* location
* created
* modified

The `/search/people` page searches profile nicknames by default. You can fiter by the other fields above.

