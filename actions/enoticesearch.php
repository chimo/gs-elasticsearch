<?php

if (!defined('GNUSOCIAL')) {
    exit(1);
}

class EnoticesearchAction extends NoticesearchAction
{
    // Same as NoticesearchAction::prepare except we use ElasticSearchNoticeStream
    // instead of SearchNoticeStream so we can pass additional arguments to the search engine.
    function prepare(array $args = array())
    {
        // We don't want parent::prepare() because then it'll use the original
        // `SearchNoticeStream` class
        Action::prepare($args);

        $this->q = $this->trimmed('q');
        $this->author = $this->trimmed('author');
        $this->type = $this->trimmed('type');
        $this->created = $this->trimmed('created');

        if (preg_match('/^#([\pL\pN_\-\.]{1,64})/ue', $this->q)) {
            common_redirect(common_local_url('tag',
                                             array('tag' => common_canonical_tag(substr($this->q, 1)))),
                            303);
        }

        if ($this->q || $this->author || $this->type || $this->created) {
            $stream  = new ElasticSearchNoticeStream(
                                $this->q,
                                $this->scoped,
                                $this->author,
                                $this->type,
                                $this->created
                            );

            $page    = $this->trimmed('page');

            if (empty($page)) {
                $page = 1;
            } else {
                $page = (int)$page;
            }

            $this->notice = $stream->getNotices((($page-1)*NOTICES_PER_PAGE), NOTICES_PER_PAGE + 1);
        }

        common_set_returnto($this->selfUrl());

        return true;
    }

    function getInstructions()
    {
        // pass, for now
    }

    // Based on searchaction.php::showForm()
    function showForm($error=null)
    {
        $q = $this->trimmed('q');
        $author = $this->trimmed('author');
        $type = $this->trimmed('type');
        $created = $this->trimmed('created');

        $page = $this->trimmed('page', 1);

        $this->elementStart('form', array('method' => 'get',
                                           'id' => 'form_search',
                                           'class' => 'form_settings',
                                           'action' => common_local_url($this->trimmed('action'))));

        $this->elementStart('fieldset');
        $this->element('legend', null, _('Search site'));
        $this->elementStart('ul', 'form_data');
        $this->elementStart('li');
        if (!common_config('site', 'fancy')) {
            $this->hidden('action', $this->trimmed('action'));
        }

        // Notice content
        $this->input('q', 'Notice text', $q);
        $this->elementEnd('li');

        // Notice author
        $this->elementStart('li');
        $this->input(
            'author',
            'Author',
            $author,
            null,
            null,
            false,
            array('placeholder' => 'user@example.org')
        );
        $this->elementEnd('li');

        // Notice type
        $this->elementStart('li');
        $this->dropdown(
            'type',
            'Notice type',
            array(
                'post' => 'post',
                'share' => 'repeat',
                'reply' => 'comment'
            ),
            null,
            true,
            $type
        );
        $this->elementEnd('li');

/* not ready yet...

        // Notice created date
        $this->elementStart('li');
        $this->input('created', 'Date created', null, null, null, false, array('type' => 'date'));
        $this->elementEnd('li');
*/

        $this->element('input', array('type'=>'submit', 'class'=>'submit', 'value'=>_m('BUTTON','Search')));
        $this->elementEnd('ul');
        $this->elementEnd('fieldset');
        $this->elementEnd('form');

        if ($q || $author || $type || $created) {
            $this->showResults($q, $page);
        }
    }

    function showNoticeForm()
    {
        // pass
    }

    function showProfileBlock()
    {
        // pass
    }
}

