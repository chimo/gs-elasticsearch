<?php

if (!defined('GNUSOCIAL')) { exit(1); }

class ElasticSearchNoticeStream extends ScopingNoticeStream
{
    function __construct($q, Profile $scoped=null, $author, $type, $created)
    {
        parent::__construct(new RawElasticSearchNoticeStream($q, $author, $type, $created), $scoped);
    }
}

