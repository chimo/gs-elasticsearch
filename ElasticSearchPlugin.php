<?php

if (!defined('GNUSOCIAL')) {
    exit(1);
}

require('lib/ElasticSearch.php');

class ElasticSearchPlugin extends Plugin
{
    const VERSION = '0.0.1';

    function onGetSearchEngine(Memcached_DataObject $target, $table, &$search_engine)
    {
        $engine = $this->createEngine($target);

        // TODO: Error handling
        //       Consider falling back to built-in search on error
        //       (i.e.: returning `true`)

        $search_engine = $engine;

        return false;
    }

    function onEndNoticeSaveWeb($action, $notice)
    {
        $this->handleNoticeSave($notice);

        return true;
    }

    function onEndNoticeSave($notice)
    {
        $this->handleNoticeSave($notice);

        return true;
    }

    function handleNoticeSave($notice)
    {
        $engine = $this->createEngine(new Notice());

        if ($notice->getVerb(true) === 'delete') {
            $engine->delete($notice);
        } else {
            $engine->index($notice);
        }
    }

    function createEngine($target)
    {
        $index_name = $this->getIndexname();
        $hosts = common_config('elasticsearch', 'hosts');

        if ($hosts === false) {
            $hosts = [ '127.0.0.1:9200' ];
        }

        return new ElasticSearch($target, null, $index_name, $hosts);
    }

    function getIndexName()
    {
        $index_name = common_config('elasticsearch', 'index_name');

        if ($index_name === false) {
            $index_name = 'gnusocial';
        }

        return $index_name;
    }

    function onPluginVersion(array &$versions)
    {
        $versions[] = array('name' => 'Elasticsearch backend',
                            'version' => self::VERSION,
                            'author' => 'chimo',
                            'homepage' => 'https://github.com/chimo/gs-elasticsearch',
                            'description' =>
                            // TRANS: Plugin description.
                            _m('ElasticSearch engine'));
        return true;
    }
}

