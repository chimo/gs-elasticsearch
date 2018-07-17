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
                        'type' => 'keyword'
                    ],
                    'text' => [
                        'type' => 'text'
                    ],
                    'verb' => [
                        'type' => 'keyword'
                    ],
                    'type' => [
                        'type' => 'keyword'
                    ],
                    'created' => [
                        'type' => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss'
                    ]
                ];
            } else { // 'profile'
                $props = [
                    'nickname' => [
                        'type' => 'text'
                    ],
                    'fullname' => [
                        'type' => 'text'
                    ],
                    'bio' => [
                        'type' => 'text'
                    ],
                    'location' => [
                        'type' => 'text'
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

            try {
                $this->client->indices()->create($params);
            } catch(Exception $e) {
                common_log(
                    LOG_ERR,
                    "Unable to create index existing $this->index_name: $e->getMessage()"
                );
            }
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

    function bulkImportNotices($notices, $indexName)
    {
        $params = array('body' => array());

        foreach($notices as $notice) {
            $params['body'][] = array(
                'index' => [
                    '_index' => $indexName . '-notice',
                    '_type' => 'notice',
                    '_id' => $notice->id,
                    'op_type' => 'create'
                ]
            );

            $params['body'][] = $this->noticetoES($notice, true);
        }

        $response = $this->client->bulk($params);

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

        $response = false;

        $params = [
            'index' => $this->index_name,
            'type' => $type,
            'id' => $object->getID()
        ];

        try {
            $response = $this->client->delete($params);

            common_log(LOG_INFO, "Deleted $type $object->id from index");
        } catch(Elasticsearch\Common\Exceptions\Missing404Exception $e) {
            // 404 Errors are okay; log as info
            common_log(
                LOG_INFO,
                "Tried to delete $type $object->id but it didn't seem to be indexed"
            );
        } catch(Exception $e) { // Log other exceptions as errors
            common_log(
                LOG_ERR,
                "Unable to delete existing $type $object->id: $e->getMessage()"
            );
        }

        return $response;
    }

    function indexNotice($notice)
    {
        $params = $this->noticeToES($notice, false);

        return $this->client->index($params);
    }

    function noticeToES($notice, $bulk=false)
    {
        try {
            $author = Profile::getKV('id', $notice->profile_id);
            $webfinger = $author->getAcctUri(false);
        } catch(Exception $e) {
            // If we can't get the author or webfinger for whatever reason
            // (this actually sometimes happen), then use an obvious placeholder
            // value (example.org)
            $webfinger = 'unknown@example.org';
        }

        try {
            $object_type = $notice->getObjectType();
        } catch(NoObjectTypeException $e) {
            common_log(
                LOG_INFO,
                "Notice $notice->id doesn't have an object_type"
            );

            $object_type = null;
        }

        $params = [
            'author' => $webfinger,
            'text' => $notice->content,
            'verb' => $notice->getVerb(true),
            'type' => $object_type,
            'created' => $notice->created
        ];

        if (!$bulk) {
            $params = [
                'id' => $notice->id,
                'index' => $this->index_name,
                'type' => $this->index_type,
                'body' => $params
            ];
        }

        return $params;
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
    function esQuery($q, $author, $type, $created)
    {
        $default_field = 'text';

        if (get_class($this->target) === 'Profile') {
            $default_field = 'nickname';
        }

        $query = [
            'bool' => [
                'must' => [],
                'filter' => []
            ],
        ];

        if ($q) {
            $query['bool']['must'][] = [
                'match' => ['text' => $q]
            ];
        } else {
            $query['bool']['must'][] = [
                'match_all' => new stdClass()
            ];
        }

        if ($author) {
            $query['bool']['filter'][] = [
                'term' => ['author' => $author]
            ];
        }

        if ($type) {
            $query['bool']['filter'][] = [
                'term' => ['verb' => $type]
            ];
        }

        $params = [
            'index' => $this->index_name,
            'type' => $this->index_type,
            'body' => [
                'query' => $query
            ]
        ];

        if ($this->sort) {
            $params['body']['sort'] = $this->sort;
        }

        $params = array_merge($params, $this->limit);

        $response = $this->client->search($params);

        // TODO: Parse response, handle errors

        $hits = $response['hits']['hits'];

        if (count($hits) === 0) {
            // No results
            //
            // Force empty result set because if we don't we end up
            // displaying the most recent notices (no WHERE clause)
            $this->target->whereAdd("1 = 2");
        } else {
            $ids = array();

            foreach($hits as $hit) {
                $ids[] = $hit['_id'];
            }

            $id_set = join(', ', $ids);

            $this->target->whereAdd("id in ($id_set)");
        }

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

    function set_sort_mode($mode)
    {
        // Sort GS results
        parent::set_sort_mode($mode);

        // Sort ES results
        switch($mode) {
            case 'reverse_chron':
                $this->sort = [
                    'created' => [
                        'order' => 'asc'
                    ]
                ];
                break;
            case 'nickname_desc':
                // TODO
                break;
            case 'nickname_asc':
                // TODO
                break;
            case 'chron':
            default:
                $this->sort = [
                    'created' => [
                        'order' => 'desc'
                    ]
                ];
                break;
        }
    }
}

