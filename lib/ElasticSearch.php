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
    private $limit;
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
        $this->limit = array();

        $this->client = ClientBuilder::create()->setHosts($hosts)->build();

        // Create index if it doesn't exist
        if (!$this->client->indices()->exists([ 'index' => $this->index_name ])) {
            if ($index_suffix === 'notice') {
                $props = [
                    'author' => [
                        'type' => 'string'
                    ],
                    'text' => [
                        'type' => 'string'
                    ],
                    'type' => [
                        'type' => 'string'
                    ],
                    'created' => [
                        'type' => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss'
                    ]
                ];
            } else { // 'profile'
                $props = [
                    'nickname' => [
                        'type' => 'string'
                    ],
                    'fullname' => [
                        'type' => 'string'
                    ],
                    'bio' => [
                        'type' => 'string'
                    ],
                    'location' => [
                        'type' => 'string'
                    ],
                    'created' => [
                        'type' => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss'
                    ],
                    'modified' => [
                        'type' => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss'
                    ]
                ];
            }

            $params = [
                'index' => $this->index_name,
                'body' => [
                    'mappings' => [
                        'notice' => [
                            'properties' => $props
                        ]
                    ]
                ]
            ];

            $response = $this->client->indices()->create($params);

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

    function delete($object)
    {
        switch(get_class($object)) {
            case 'Notice':
                $type = 'notice';
                break;
            case 'Profile':
                $type = 'profile';
                break;
            default:
                break;
        }

        $params = [
            'index' => $this->index_name,
            'type' => $type,
            'id' => $object->getID()
        ];

        try {
            $response = $this->client->delete($params);

            common_log(LOG_INFO, "Deleted $type $object->getID() from index");
        } catch(Missing404Exception $e) { // 404 Errors are okay; log as info
            common_log(
                LOG_INFO,
                "Tried to delete $type $object->getID() but it didn't seem to be indexed"
            );
        } catch(Exception $e) { // Log other exceptions as errors
            common_log(
                LOG_ERROR,
                "Unable to delete existing $type $object->getID(): $e->getMessage()"
            );
        }

        return $response;
    }

    function indexNotice($notice)
    {
        $author = Profile::getKV('id', $notice->profile_id);
        $webfinger = $author->getAcctUri(false);

        $params = [
            'index' => $this->index_name,
            'type' => $this->index_type,
            'id' => $notice->id,
            'body' => [
                'author' => $webfinger,
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

        $params = array_merge($params, $this->limit);

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
        $this->limit = array(
            'from' => $offset,
            'size' => $count
        );

        return parent::limit($offset, $count, $rss);
    }
}

