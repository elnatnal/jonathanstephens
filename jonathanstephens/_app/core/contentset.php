<?php
/**
 * TaxonomySet
 * Special content container for dealing with content display
 *
 * @author      Jack McDade
 * @author      Fred LeBlanc
 * @author      Mubashar Iqbal
 * @package     Core
 * @copyright   2013 Statamic
 */
class ContentSet
{
    private $content = array();
    private $prepared = false;
    private $supplemented = false;


    /**
     * Create ContentSet
     *
     * @param array  $content  List of content to start with
     * @return ContentSet
     */
    public function __construct($content)
    {
        $this->content = $this->makeUnique($content);
    }


    /**
     * Gets a count of the content contained in this set
     *
     * @return int
     */
    public function count()
    {
        return count($this->content);
    }


    /**
     * Ensures that the given content array is unique
     *
     * @param array  $content  Content to loop through
     * @return array
     */
    public function makeUnique($content)
    {
        if (!is_array($content)) {
            return array();
        }

        $urls = array();

        foreach ($content as $key => $item) {
            if (in_array($item['url'], $urls)) {
                unset($content[$key]);
                continue;
            }

            array_push($urls, $item['url']);
        }

        return $content;
    }


    /**
     * Filters the current content set down based on the filters given
     *
     * @param array  $filters  Filters to use to narrow down content
     * @return void
     * @throws Exception
     */
    public function filter($filters)
    {
        $filters = Helper::ensureArray($filters);

        // nothing to filter, abort
        if (!$this->count()) {
            return;
        }

        $since_date     = null;
        $until_date     = null;
        $remove_hidden  = null;
        $keep_type      = "all";
        $folders        = null;
        $conditions     = null;
        $located        = false;


        // standardize filters
        // -------------------
        $given_filters = $filters;
        $filters = array(
            'show_all'    => (isset($given_filters['show_all']))       ? $given_filters['show_all']         : null,
            'since'       => (isset($given_filters['since']))          ? $given_filters['since']            : null,
            'until'       => (isset($given_filters['until']))          ? $given_filters['until']            : null,
            'show_past'   => (isset($given_filters['show_past']))      ? $given_filters['show_past']        : null,
            'show_future' => (isset($given_filters['show_future']))    ? $given_filters['show_future']      : null,
            'type'        => (isset($given_filters['type']))           ? strtolower($given_filters['type']) : null,
            'folders'     => (isset($given_filters['folders']))        ? $given_filters['folders']          : null,
            'conditions'  => (isset($given_filters['conditions']))     ? $given_filters['conditions']       : null,
            'located'     => (isset($given_filters['located']))        ? $given_filters['located']          : null
        );


        // determine filters
        // -----------------
        if ($filters['show_all'] === false) {
            $remove_hidden = true;
        }

        if ($filters['since']) {
            $since_date = Date::resolve($filters['since']);
        }

        if ($filters['show_past'] === false && (!$since_date || $since_date < time())) {
            $since_date = time();
        }

        if ($filters['until']) {
            $until_date = Date::resolve($filters['until']);
        }

        if ($filters['show_future'] === false && (!$until_date || $until_date > time())) {
            $until_date = time();
        }

        if ($filters['type'] === "entries" || $filters['type'] === "pages") {
            $keep_type = $filters['type'];
        }

        if ($filters['folders']) {
            $folders = Helper::parseForFolders($filters['folders']);
        }

        if ($filters['conditions']) {
            $conditions = Parse::conditions($filters['conditions']);
        }

        if ($filters['located']) {
            $located = true;
        }


        // run filters
        // -----------
        foreach ($this->content as $key => $data) {
            // entry or page removal
            if ($keep_type === "pages" && !$data['_is_page']) {
                unset($this->content[$key]);
                continue;
            } elseif ($keep_type === "entries" && !$data['_is_entry']) {
                unset($this->content[$key]);
                continue;
            }

            // check if this is hidden content
            if ($remove_hidden && strpos($data['_local_path'], "/_") !== false) {
                unset($this->content[$key]);
                continue;
            }

            // folder
            if ($folders) {
                $keep = false;
                foreach ($folders as $folder) {
                    if ($folder === "*" || $folder === "/*") {
                        // include all
                        $keep = true;
                        break;
                    } elseif (substr($folder, -1) === "*") {
                        // wildcard check
                        if (strpos($data['_folder'], substr($folder, 0, -1)) === 0) {
                            $keep = true;
                            break;
                        }
                    } else {
                        // plain check
                        if ($folder == $data['_folder']) {
                            $keep = true;
                            break;
                        }
                    }
                }

                if (!$keep) {
                    unset($this->content[$key]);
                    continue;
                }
            }

            // since & show past
            if ($since_date && $data['datestamp'] && $data['datestamp'] < $since_date) {
                unset($this->content[$key]);
                continue;
            }

            // until & show future
            if ($until_date && $data['datestamp'] && $data['datestamp'] > $until_date) {
                unset($this->content[$key]);
                continue;
            }

            // conditions
            if ($conditions) {
                $case_sensitive_taxonomies = Config::getTaxonomyCaseSensitive();

                foreach ($conditions as $field => $instructions) {                    
                    try {                        
                        // are we looking for existence?
                        if ($instructions['kind'] === "existence") {
                            if ($instructions['type'] === "has") {
                                if (!isset($data[$field]) || !$data[$field]) {
                                    throw new Exception("Does not fit condition");
                                }
                            } elseif ($instructions['type'] === "lacks") {
                                if (isset($data[$field]) && $data[$field]) {
                                    throw new Exception("Does not fit condition");
                                }
                            } else {
                                throw new Exception("Unknown existence type");
                            }
                            
                        // are we looking for a comparison?
                        } elseif ($instructions['kind'] === "comparison") {
                            $is_taxonomy     = Taxonomy::isTaxonomy($field);
                            $case_sensitive  = ($is_taxonomy && $case_sensitive_taxonomies);

                            if (!isset($data[$field])) {
                                $field = false;
                                $values = null;
                            } else {
                                if ($case_sensitive) {
                                    $field  = $data[$field];
                                    $values = $instructions['value'];
                                } else {
                                    $field  = (is_array($data[$field]))          ? array_map('strtolower', $data[$field])          : strtolower($data[$field]);
                                    $values = (is_array($instructions['value'])) ? array_map('strtolower', $instructions['value']) : strtolower($instructions['value']);
                                }
                            }

                            // convert boolean-like statements to boolean values
                            if (is_array($values)) {
                                foreach ($values as $item => $value) {
                                    if ($value == "true" || $value == "yes") {
                                        $values[$item] = true;
                                    } elseif ($value == "false" || $value == "no") {
                                        $values[$item] = false;
                                    }
                                }
                            } else {
                                if ($values == "true" || $values == "yes") {
                                    $values = true;
                                } elseif ($values == "false" || $values == "no") {
                                    $values = false;
                                }
                            }

                            // equal comparisons
                            if ($instructions['type'] == "equal") {
                                // if this isn't set, it's not equal
                                if (!$field) {
                                    throw new Exception("Does not fit condition");
                                }

                                if (!is_array($field)) {
                                    if ($field != $values) {
                                        throw new Exception("Does not fit condition");
                                    }
                                } elseif (!in_array($values, $field)) {
                                    throw new Exception("Does not fit condition");
                                }

                            // not-equal comparisons
                            } elseif ($instructions['type'] == "not equal") {
                                // if this isn't set, it's not equal, continue
                                if (!$field) {
                                    continue;
                                }

                                if (!is_array($field)) {
                                    if ($field == $values) {
                                        throw new Exception("Does not fit condition");
                                    }
                                } elseif (in_array($values, $field)) {
                                    throw new Exception("Does not fit condition");
                                }

                            // contains comparisons
                            } elseif ($instructions['type'] == "in") {
                                if (!isset($field)) {
                                    throw new Exception("Does not fit condition");
                                }

                                if (is_array($field)) {
                                    $found = false;

                                    foreach ($field as $option) {
                                        if (in_array($option, $values)) {
                                            $found = true;
                                            break;
                                        }
                                    }

                                    if (!$found) {
                                        throw new Exception("Does not fit condition");
                                    }
                                } elseif (!in_array($field, $values)) {
                                    throw new Exception("Does not fit condition");
                                }
                            }

                        // we don't know what this is
                        } else {
                            throw new Exception("Unknown kind of condition");
                        }
                        
                    } catch (Exception $e) {
                        unset($this->content[$key]);
                        continue;
                    }
                }
            }

            // located
            if ($located && (!isset($data['coordinates']))) {
                unset($this->content[$key]);
                continue;
            }
        }
    }


    /**
     * Sorts the current content by $field and $direction
     *
     * @param string  $field  Field to sort on
     * @param string  $direction  Direction to sort
     * @return void
     */
    public function sort($field="order_key", $direction=null)
    {
        // no content, abort
        if (!count($this->content)) {
            return;
        }

        // sort by random, short-circuit
        if ($field == "random") {
            shuffle($this->content);
            return;
        }

        // sort by field
        usort($this->content, function($item_1, $item_2) use ($field) {
            // grab values, translating some user-facing names into internal ones
            switch ($field) {
                case "order_key":
                    $value_1 = $item_1['_order_key'];
                    $value_2 = $item_2['_order_key'];
                    break;

                case "number":
                    $value_1 = $item_1['_order_key'];
                    $value_2 = $item_2['_order_key'];
                    break;

                case "datestamp":
                    $value_1 = $item_1['datestamp'];
                    $value_2 = $item_2['datestamp'];
                    break;

                case "date":
                    $value_1 = $item_1['datestamp'];
                    $value_2 = $item_2['datestamp'];
                    break;

                case "folder":
                    $value_1 = $item_1['_folder'];
                    $value_2 = $item_2['_folder'];
                    break;

                case "distance":
                    $value_1 = $item_1['distance_km'];
                    $value_2 = $item_2['distance_km'];
                    break;

                // not a special case, grab the field values if they exist
                default:
                    $value_1 = (isset($item_1[$field])) ? $item_1[$field] : null;
                    $value_2 = (isset($item_2[$field])) ? $item_2[$field] : null;
                    break;
            }

            // compare the two values
            // ----------------------------------------------------------------
            return Helper::compareValues($value_1, $value_2);
        });

        // apply sort direction
        if (is_null($direction)) {
            reset($this->content);
            $sample = $this->content[key($this->content)];

            // if we're sorting by order_key and it's date-based order, default sorting is 'desc'
            if ($field == "order_key" && $sample['_order_key'] && $sample['datestamp']) {
                $direction = "desc";
            } else {
                $direction = "asc";
            }
        }

        // do we need to flip the order?
        if (Helper::pick($direction, "asc") == "desc") {
            $this->content = array_reverse($this->content);
        }
    }


    /**
     * Limits the number of items kept in the set
     *
     * @param int  $limit  The maximum number of items to keep
     * @param int  $offset  Offset the starting point of the chop
     * @return void
     */
    public function limit($limit=null, $offset=0)
    {
        if (is_null($limit) && $offset === 0) {
            return;
        }

        $this->content = array_slice($this->content, $offset, $limit, true);
    }


    /**
     * Grabs one page from a paginated set
     *
     * @param int  $page_size  Size of page to grab
     * @param int  $page  Page number to grab
     * @return void
     */
    public function isolatePage($page_size, $page)
    {
        $count = $this->count();

        // return the last page of results if $page is out of range
        if (Config::getFixOutOfRangePagination()) {
            if ($page_size * $page > $count) {
                $page = ceil($count / $page_size);
            } elseif ($page < 1) {
                $page = 1;
            }
        }

        $offset = ($page - 1) * $page_size;
        $this->limit($page_size, $offset);
    }



    /**
     * Prepares the data for use in loops
     *
     * @param bool  $parse_content  Parse content? This is a performance hit.
     * @return void
     */
    public function prepare($parse_content=true)
    {
        if ($this->prepared) {
            return;
        }

        $this->prepared = true;
        $count = $this->count();
        $i = 1;

        // loop through the content adding contextual data
        foreach ($this->content as $key => $item) {
            $this->content[$key]['first']         = ($i === 1);
            $this->content[$key]['last']          = ($i === $count);
            $this->content[$key]['count']         = $i;
            $this->content[$key]['total_results'] = $count;

            // parse full content if that's been requested
            if ($parse_content && isset($item['_file'])) {
                $raw_file = substr(File::get($item['_file']), 3);
                $divide = strpos($raw_file, "\n---");
                $this->content[$key]['content_raw'] = trim(substr($raw_file, $divide + 4));
                $this->content[$key]['content'] = Content::parse($this->content[$key]['content_raw'], $item);
            }

            $i++;
        }
    }


    /**
     * Supplements the content in the set
     *
     * @param array  $context  Context for supplementing
     * @return void
     */
    public function supplement($context=array())
    {
        if ($this->supplemented) {
            return;
        }

        $this->supplemented = true;
        $context = Helper::ensureArray($context);

        // determine context
        $given_context = $context;
        $context = array(
            'locate_with'     => (isset($given_context['locate_with']))     ? $given_context['locate_with']     : null,
            'center_point'    => (isset($given_context['center_point']))    ? $given_context['center_point']    : null,
            'pop_up_template' => (isset($given_context['pop_up_template'])) ? $given_context['pop_up_template'] : null,
            'list_helpers'    => (isset($given_content['list_helpers']))    ? $given_context['list_helpers']    : true,
            'context_urls'    => (isset($given_context['context_urls']))    ? $given_context['context_urls']    : true
        );

        // set up helper variables
        $center_point = false;
        if ($context['center_point'] && preg_match(Pattern::COORDINATES, $context['center_point'], $matches)) {
            $center_point = array($matches[1], $matches[2]);
        }

        // contextual urls are based on current page, not individual data records
        // we can figure this out once and then set it with each one
        if ($context['context_urls']) {
            $raw_url   = Request::getResourceURI();
            $page_url  = preg_replace(Pattern::ORDER_KEY, '', Request::getResourceURI());
        }


        // loop through content, supplementing each record with data
        foreach ($this->content as $content_key => $data) {

            // locate
            if ($context['locate_with']) {
                $location_data = (isset($data[$context['locate_with']])) ? $data[$context['locate_with']] : null;

                // check that location data is fully set
                if (is_array($location_data) && isset($location_data['latitude']) && $location_data['latitude'] && isset($location_data['longitude']) && $location_data['longitude']) {
                    $data['latitude']     = $location_data['latitude'];
                    $data['longitude']    = $location_data['longitude'];
                    $data['coordinates']  = $location_data['latitude'] . "," . $location_data['longitude'];

                    // get distance from center
                    if ($center_point) {
                        $location = array($data['latitude'], $data['longitude']);
                        $data['distance_km'] = Math::getDistanceInKilometers($center_point, $location);
                        $data['distance_mi'] = Math::convertKilometersToMiles($data['distance_km']['distance_km']);
                    }
                }
            }

            // pop-up template
            if ($context['pop_up_template']) {
                $data['marker_pop_up_content'] = Content::parse($context['pop_up_template'], $data, "html");
            }

            // contexual urls
            if ($context['context_urls']) {
                $data['raw_url']  = $raw_url;
                $data['page_url'] = $page_url;
            }

            // loop through content to add data for variables that are arrays
            foreach ($data as $key => $value) {

                // Only run on zero indexed arrays/loops
                if (is_array($value) && isset($value[0]) && ! is_array($value[0])) {

                    // list helpers
                    if ($context['list_helpers']) {
                        // make automagic lists
                        $data[$key . "_list"]                    = join(", ", $value);
                        $data[$key . "_spaced_list"]             = join(" ", $value);
                        $data[$key . "_option_list"]             = join("|", $value);
                        $data[$key . "_ordered_list"]            = "<ol><li>" . join("</li><li>", $value) . "</li></ol>";
                        $data[$key . "_unordered_list"]          = "<ul><li>" . join("</li><li>", $value) . "</li></ul>";
                        $data[$key . "_sentence_list"]           = Helper::makeSentenceList($value);
                        $data[$key . "_ampersand_sentence_list"] = Helper::makeSentenceList($value, "&", false);

                        // handle taxonomies
                        if (Taxonomy::isTaxonomy($key)) {
                            $url_list = array_map(function($item) use ($data, $key, $value) {
                                return '<a href="' . Taxonomy::getURL($data['_folder'], $key, $item) . '">' . $item . '</a>';
                            }, $value);

                            $data[$key . "_url_list"]                    = join(", ", $url_list);
                            $data[$key . "_spaced_url_list"]             = join(" ", $url_list);
                            $data[$key . "_ordered_url_list"]            = "<ol><li>" . join("</li><li>", $url_list) . "</li></ol>";
                            $data[$key . "_unordered_url_list"]          = "<ul><li>" . join("</li><li>", $url_list) . "</li></ul>";
                            $data[$key . "_sentence_url_list"]           = Helper::makeSentenceList($url_list);
                            $data[$key . "_ampersand_sentence_url_list"] = Helper::makeSentenceList($url_list, "&", false);
                        }
                    }
                }
            }

            // update content with supplemented data merged with global config data
            $this->content[$content_key] = array_merge(Config::getAll(), $data);
        }
    }


    /**
     * Get the data stored within
     *
     * @param bool  $parse_content  Parse content?
     * @param bool  $supplement  Supplement content?
     * @return array
     */
    public function get($parse_content=true, $supplement=true)
    {
        if ($supplement) {
            $this->supplement();
        }
        $this->prepare($parse_content);
        return $this->content;
    }
}