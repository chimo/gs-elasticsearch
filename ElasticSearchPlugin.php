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
        if ($this->isEnabled()) {
            $engine = $this->createEngine($target);

            // TODO: Error handling

            $search_engine = $engine;

            return false;
        }

        return true;
    }

    function onEndNoticeSaveWeb($action, $notice)
    {
        $this->indexNotice($notice);

        return true;
    }

    function onEndNoticeSave($notice)
    {
        $this->indexNotice($notice);

        return true;
    }

    function indexNotice($notice)
    {
        if ($this->isEnabled()) {
            $engine = $this->createEngine(new Notice());

            $response = $engine->index($notice);

            // TODO: Error handling
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

    function isEnabled()
    {
        return common_config('elasticsearch', 'enabled');
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

