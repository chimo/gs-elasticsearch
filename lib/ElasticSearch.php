<?php

if (!defined('GNUSOCIAL')) {
    exit(1);
}

require(__DIR__ . '/../vendor/autoload.php');
require(INSTALLDIR . '/lib/search_engines.php');

use Elasticsearch\ClientBuilder;

class ElasticSearch extends SearchEngine
{
    private $client;
    private $index_name;
    protected $table;
    protected $target;

    function __construct($target, $table, $index_name, $hosts)
    {
        // We will be searching Notices and Profiles.
        // We want them to be in different indexes, so we suffix the
        // object type to the base 'index name'
        $index_suffix = strtolower(get_class($target));

        $this->target = $target;
        $this->table = $table;
        $this->index_name = $index_name . '-' . $index_suffix;
        $this->index_type = $index_suffix;
        $this->hosts = $hosts;

        $this->client = ClientBuilder::create()->setHosts($hosts)->build();

        // Create index if it doesn't exist
        if (!$this->client->indices()->exists([ 'index' => $this->index_name ])) {
            $response = $this->client->indices()->create([ 'index' => $this->index_name ]);

            // TODO: Parse response, handle errors
        }
    }

    function index($object)
    {
        $response = 'Trying to index unsupported object. Aborting.';

        switch(get_class($object)) {
            case 'Notice':
                $response = $this->indexNotice($object);
                break;
            case 'Profile':
                $response = $this->indexProfile($object);
                break;
            default:
                break;
        }

        return $response;
    }

    function indexNotice($notice)
    {
        $author = Profile::getKV('id', $notice->profile_id);

        $params = [
            'index' => $this->index_name,
            'type' => $this->index_type,
            'id' => $notice->id,
            'body' => [
                'author' => $author->nickname,
                'text' => $notice->content,
                'type' => $notice->getVerb(true),
                'created' => $notice->created
            ]
        ];

        return $this->client->index($params);
    }

    function indexProfile($profile)
    {
        $params = [
            'index' => $this->index_name,
            'type' => $this->index_type,
            'id' => $profile->id,
            'body' => [
                'nickname' => $profile->nickname,
                'fullname' => $profile->fullname,
                'bio' => $profile->bio,
                'location' => $profile->location,
                'created' => $profile->created,
                'modified' => $profile->modified
            ]
        ];

        $response = $this->client->index($params);

        // TODO: Parse response, handle errors
    }

    // From SearchEngine class
    function query($q)
    {
        $default_field = 'text';

        if (get_class($this->target) === 'Profile') {
            $default_field = 'nickname';
        }

        $params = [
            'index' => $this->index_name,
            'type' => $this->index_type,
            'body' => [
                'query' => [
                    'query_string' => [
                        'default_field' => $default_field,
                        'query' => $q
                    ]
                ]
            ]
        ];

        $response = $this->client->search($params);

        // TODO: Parse response, handle errors

        $hits = $response['hits']['hits'];

        if (count($hits) === 0) {
            return false;
        }

        $ids = array();

        foreach($hits as $hit) {
            $ids[] = $hit['_id'];
        }

        $id_set = join(', ', $ids);

        $this->target->whereAdd("id in ($id_set)");

        return true;
    }

    // From SearchEngine class
    function limit($offset, $count, $rss = false)
    {
        // TODO
    }

    // From SearchEngine class
    function set_sort_mode($mode)
    {
        // TODO
    }
}

