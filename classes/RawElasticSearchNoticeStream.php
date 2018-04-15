<?php

if (!defined('GNUSOCIAL')) { exit(1); }

require_once INSTALLDIR . '/lib/searchnoticestream.php';

class RawElasticSearchNoticeStream extends RawSearchNoticeStream
{
    protected $q;
    protected $author;
    protected $type;
    protected $created;

    function __construct($q, $author, $type, $created)
    {
        $this->q = $q;
        $this->author = $author;
        $this->type = $type;
        $this->created = $created;
    }

    function getNoticeIds($offset, $limit, $since_id, $max_id)
    {
        $notice = new Notice();

        $search_engine = $notice->getSearchEngine('notice');
        $search_engine->set_sort_mode('chron');
        $search_engine->limit($offset, $limit);

        $ids = array();

        $search_engine->esQuery($this->q, $this->author, $this->type, $this->created);

        if ($notice->find()) {
            while ($notice->fetch()) {
                $ids[] = $notice->id;
            }
        }

        return $ids;
    }
}

