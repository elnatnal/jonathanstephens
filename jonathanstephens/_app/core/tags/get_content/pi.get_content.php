<?php
class Plugin_get_content extends Plugin
{
    public function index()
    {
        $from = $this->fetchParam('from', false); // defaults to null

        if (!$from) {
            return null;
        }

        $content_set = ContentService::getContentByURL($from);

        // filter
        $content_set->filter(array(
            'show_all'    => $this->fetchParam('show_hidden', false, null, true, false),
            'show_past'   => $this->fetchParam('show_past', true, null, true),
            'show_future' => $this->fetchParam('show_future', false, null, true),
            'type'        => 'all',
            'conditions'  => trim($this->fetchParam('conditions', null, false, false, false))
        ));
        
        // check for results
        if (!$content_set->count()) {
            return Parse::tagLoop($this->content, array(array('no_results' => true)));
        }

        // if content is used in this entries loop, parse it
        $parse_content = (bool) preg_match(Pattern::USING_CONTENT, $this->content);
//rd($content_set->get($parse_content));
        return Parse::tagLoop($this->content, $content_set->get($parse_content));

    }

}
