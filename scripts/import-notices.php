#!/usr/bin/env php
<?php

define('INSTALLDIR', realpath(dirname(__FILE__) . '/../../../..'));

$longoptions = array('since==', 'batchsize==');
$shortoptions = 's::b::';

$helptext = <<<END_OF_HELP
import-notices.php [options]
Import notices missing from the elastic search index.

    -s --since      Start at a specific notice id
    -b --batchsize  How many notices to send to ES at one time

END_OF_HELP;

require_once INSTALLDIR . '/scripts/commandline.inc';

$since = get_option_value('s', 'since');
$batchSize = get_option_value('b', 'batchsize');

$index = 0;

if (intval($batchSize) > 0) {
    $limit = intval($batchSize);
} else {
    $limit = 1000;
}

if (intval($since) > 0) {
    $lastId = $since;
} else {
    $lastId = 0;
}

$engine = createEngine();

$indexName = getIndexName();

$statuses = array();

do {
    unset($notice, $notices, $response);

    $notice = new Notice();

    // Don't index deleted notices
    $notice->whereAdd('verb != "' . ActivityVerb::DELETE . '"');
    $notice->whereAdd('verb != "delete"');

    // Instead of using an offset with limit to paginate over the notices
    // order by id (which is a SQL-indexed column) and keep track of which on
    // we imported last. Seems to be a lot more efficient.
    $notice->whereAdd('id > ' . $lastId);
    $notice->orderBy('id');

    $notice->limit($limit);
    $notice->find();
    $notices = $notice->fetchAll();

    if (count($notices) === 0) {
        break;
    }

    // User feedback
    echo 'Importing batch ' . ($index + 1) . " ($limit notices)...\n";

    $response = $engine->bulkImportNotices($notices, $indexName);

    $lastId = end($notices)->id;
    echo "Last id: $lastId\n";

    // Collect some stats (skipped, (un)successful import)
    foreach($response['items'] as $item) {
        if (!isset($statuses[$item['create']['status']])) {
            $statuses[$item['create']['status']] = 1;
        } else {
            $statuses[$item['create']['status']] += 1;
        }
    }

    $index++;
} while(count($notices) > 0);

foreach($statuses as $status => $count) {
    switch($status) {
        case "201":
            echo "$count notices imported\n";
            break;
        case "409":
            echo "$count notices were skipped because they were already indexed\n";
            break;
        default:
            echo "$count notices returned status $status\n";
            break;
    }
}

function getIndexName()
{
    $index_name = common_config('elasticsearch', 'index_name');

    if ($index_name === false) {
        $index_name = 'gnusocial';
    }

    return $index_name;
}

function createEngine()
{
    $hosts = common_config('elasticsearch', 'hosts');
    $index_name = getIndexName();

    if ($hosts === false) {
        $hosts = ['127.0.0.1:9200'];
    }

    return new ElasticSearch(new Notice(), null, $index_name, $hosts);
}

