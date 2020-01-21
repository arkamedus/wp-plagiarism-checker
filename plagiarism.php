<?php
/*
Plugin Name: Plagiarism
Plugin URI: http://www.freeplugin.org/plagiarism-wordpress-plugin.html 
Description: Plugin for Checking whether your posts and pages are accidentally duplicating other web content. Avoid plagiarism and duplicate content, keep Google happy. Also detects copies of your posts that others may have copied.Options page will be in the Settings menu.
Author: seoroma
Version: 1.1.1
Author URI: http://www.freeplugin.org
Contributors: arkamedus
License: GPLv3
*/

if (is_admin()) $plagiarism = new Plagiarism();


class Plagiarism
{

    const LANG = 'plagiarism';
    const USER_AGENT = "Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.0; Trident/5.0; .NET4.0C)";
    const GOOGLE_URL = "http://www.google.com/search?hl=en&q=";
    const GOOGLE_URL_4 = "http://www.google.com/search?hl=en&num=4&q=";

    const GOOGLE_BLOG_URL = "http://www.google.com/search?tbm=blg&hl=en&q=";
    const GOOGLE_BLOG_URL_4 = "http://www.google.com/search?tbm=blg&hl=en&num=4&q=";

    const YAHOO_URL = "http://search.yahoo.com/search?ei=UTF-8&p=%2B";
    const BING_URL = "http://www.bing.com/search?q=";
    const BING_BLOG_URL = "http://www.bing.com/blogs/search?q=";

    const DUCKDUCKGO_URL = "http://duckduckgo.com/?q=";

    const THICKBOX_IFRAME = "&TB_iframe=true&height=600&width=800";
    const THICKBOX_INLINE = "#TB_inline?inlineId=plagiarismDebug&height=800&width=670";

    public $plagiarism_search = 'google';
public $current_search_key = '';

    protected $current_search_result = '';

    public function __construct()
    {
        add_action('admin_print_styles', array(&$this, 'add_header_styles'));
        add_action('admin_enqueue_scripts', array(&$this, 'add_header_scripts'));
        add_action('admin_menu', array(&$this, 'admin_actions'));
        add_action('add_meta_boxes', array(&$this, 'add_meta_boxes'));
        add_action('wp_dashboard_setup', array(&$this, 'add_dashboard_meta_boxes'));
        add_action('transition_post_status', array(&$this, 'check_editor_required'));

        add_action('wp_ajax_plagiarism_render_meta_box', array(&$this, 'render_meta_box'));
        add_action('wp_ajax_plagiarism_clear_results', array(&$this, 'clear_results'));
    }

    public function add_header_styles()
    {
        global $pagenow;
        if (in_array($pagenow, array('post.php', 'admin-ajax.php', 'options-general.php'))) {
            wp_enqueue_style('plagiarism_css', plugins_url('assets/plagiarism.css', __FILE__), false, false, 'all');
            wp_enqueue_style('thickbox');
        }
    }

    public function add_header_scripts()
    {
        global $pagenow;
        if (in_array($pagenow, array('post.php', 'admin-ajax.php'))) {
            // EMBED THE JAVASCRIPT FILE THAT MAKES THE AJAX REQUEST
            wp_enqueue_script('jquery');
            wp_enqueue_script('thickbox');

            if ((get_option('plagiarism_editor_required') == 'on') && (!$this->is_editor())) {
                wp_enqueue_script('plagiarism_editor_required', plugins_url('assets/plagiarism_editor_required.js', __FILE__), array('jquery'));
            }

            wp_enqueue_script('plagiarism_ajax_request', plugins_url('assets/plagiarism_ajax.js', __FILE__), array('jquery'));

            // declare the URL to the file that handles the AJAX request (wp-admin/admin-ajax.php)
            wp_localize_script('plagiarism_ajax_request', 'plagiarism_ajax',
                array('ajaxurl' => admin_url('admin-ajax.php'),
                    'post' => $_REQUEST['post'],
                    'slice' => $this->get_user_option('plagiarism_ajax_slice'),
                    'nonce' => wp_create_nonce('plagiarism_nonce')
                )
            );
        }
    }

    public function update_user_option($option_name, $option_value)
    {
        $uid = get_current_user_id();
        if ($uid == 0) return false;
        return update_user_meta($uid, $option_name, $option_value);
    }

    public function get_user_option($option_name)
    {
        $uid = get_current_user_id();
        if ($uid == 0) return false;
        return get_user_meta($uid, $option_name, true);
    }

    public function admin_actions()
    {

        //SET DEFAULT OPTIONS
        if (!get_option('plagiarism_editor_required')) update_option('plagiarism_editor_required', 'off'); //Note this is global not User option

        if (!$this->get_user_option('plagiarism_chunk_size')) $this->update_user_option('plagiarism_chunk_size', '10');
        if (!$this->get_user_option('plagiarism_chunk_step')) $this->update_user_option('plagiarism_chunk_step', '6');
        if (!$this->get_user_option('plagiarism_ajax_slice')) $this->update_user_option('plagiarism_ajax_slice', '10');
        if (!$this->get_user_option('plagiarism_sleep')) $this->update_user_option('plagiarism_sleep', '0');

        if (!$this->get_user_option('plagiarism_auto_check')) $this->update_user_option('plagiarism_auto_check', 'off');

        if (!$this->get_user_option('plagiarism_debug')) $this->update_user_option('plagiarism_debug', 'off');
        if (!$this->get_user_option('plagiarism_use_proxy')) $this->update_user_option('plagiarism_use_proxy', 'off');

        if (!$this->get_user_option('plagiarism_search')) $this->update_user_option('plagiarism_search', 'google');

        if (!$this->get_user_option('plagiarism_exclude_domains')) $this->update_user_option('plagiarism_exclude_domains', parse_url(home_url(), PHP_URL_HOST));

        if (!$this->get_user_option('plagiarism_proxies')) $this->update_user_option('plagiarism_proxies', '');
        if (!$this->get_user_option('plagiarism_current_proxy')) $this->update_user_option('plagiarism_current_proxy', '0');

        add_options_page(__("Plagiarism", self::LANG), __("Plagiarism", self::LANG), 'manage_options', "plagiarism-admin", array(&$this, 'admin_options'));
    }


    public function add_meta_boxes()
    {
        add_meta_box('plagiarism_id', __('Plagiarism', self::LANG), array(&$this, 'meta_inner_box'), 'post', 'advanced', 'high');
        add_meta_box('plagiarism_id', __('Plagiarism', self::LANG), array(&$this, 'meta_inner_box'), 'page', 'advanced', 'high');
    }


    public function add_dashboard_meta_boxes()
    {
        wp_add_dashboard_widget('plagiarism', 'SEO News by Plagiarism', array(&$this, 'feed_box'));
    }


    public function feed_box()
    {
        $feedurl = 'http://www.seo.roma.it/news/feed/';
        $select = 6;

        $rss = fetch_feed($feedurl);
        if (!is_wp_error($rss)) { // Checks that the object is created correctly
            $maxitems = $rss->get_item_quantity($select);
            $rss_items = $rss->get_items(0, $maxitems);
        }
        if (!empty($maxitems)) {
            ?>
            <div class="rss-widget">
                <ul>
                    <?php
                    foreach ($rss_items as $item) {
                        ?>
                        <li><a class="rsswidget"
                               href='<?php echo $item->get_permalink(); ?>'><?php echo $item->get_title(); ?></a> <span
                                    class="rss-date"><?php echo date_i18n(get_option('date_format'), strtotime($item->get_date('j F Y'))); ?></span>
                        </li>
                    <?php } ?>
                </ul>
            </div>
            <?php
        }
        $x = is_rtl() ? 'left' : 'right'; // This makes sure that the positioning is also correct for right-to-left languages
        echo '<style type="text/css">#plagiarism_id {float:' . $x . ';}</style>';
    }


    public function get_proxies()
    {

        $proxies = explode("\n", $this->get_user_option('plagiarism_proxies'));

        $result = array();
        foreach ($proxies as $key => $string) {
            if (trim($string) != '') {
                $p = explode("@", $string); // split at the @

                if (isset($p[1])) {
                    $h = explode(':', $p[1]); // host and port
                    $c = explode(':', $p[0]); // user and password
                } else {
                    $h = explode(':', $p[0]); // host and port
                    $c = array();
                }
                $result[$key] = array(host => trim($h[0]), port => trim($h[1]), user => trim($c[0]), pass => trim($c[1]));
            }
        }
        return $result;
    }

    public function clear_results()
    {
        $is_ajax = (isset($_POST['plagiarism_ajax_call']) && $_POST['plagiarism_ajax_call'] == 'true');
        if ($is_ajax) {
            check_ajax_referer('plagiarism_nonce');
            $post_id = $_POST['post_id'];
        } else {
            $post_id = $_REQUEST['post'];
        }
        delete_post_meta($post_id, 'plagiarism_result');
        echo 'Cleared';
        if ($is_ajax) {
            exit();
        }
    }

    public function is_editor()
    {
        $cu = wp_get_current_user();
        return (count(array_intersect($cu->roles, array('administrator', 'editor'))) > 0);
    }

    public function check_editor_required($new_status, $oldstatus = '', $post = false)
    {

        $post_id = $_REQUEST['post_ID'];

        if (in_array($new_status, array('publish', 'future'))) {  //Are we publishing, even in the future?

            $editor_required = (get_option('plagiarism_editor_required') == 'on');
            $check_ok = $this->check_post($post_id);

            if ($editor_required && (!$check_ok) && (!$this->is_editor())) {
                wp_update_post(array('ID' => $post_id, 'post_status' => 'pending'));
            }
        }
    }

    public function render_meta_box()
    {
        $is_ajax = (isset($_POST['plagiarism_ajax_call']) && $_POST['plagiarism_ajax_call'] == 'true');
        if ($is_ajax) {
            check_ajax_referer('plagiarism_nonce');
            $post_id = $_POST['post_id'];
            $this->plagiarism_search = $_POST['search'];
            $this->check_post($post_id);
        } else {
            $post_id = $_REQUEST['post'];
        }

        $chunks = json_decode(base64_decode(get_post_meta($post_id, 'plagiarism_result', true)));

        if (count($chunks) > 0) {

            $editor_required = (get_option('plagiarism_editor_required') == 'on');
            $check_ok = (get_post_meta($post_id, 'plagiarism_duplicate', true) == 'false');

            echo '<script type="text/javascript">noDuplicates = ' . (($check_ok) ? 'true' : 'false') . ';</script>';

            $plagiarism_rows = '';
            $plagiarism_count_accept = 0;
            $plagiarism_count_reject = 0;
            $plagiarism_count_error = 0;
            foreach ($chunks as $key => $value) {
                //$key = iconv($key, 'UTF-8', $key);
                $url = $this->build_query($key, $this->plagiarism_search);
                $link = '<a href="' . $url . '" target="_blank" title="' . $key . '" >' . $key . '</a>';

                if ($value == 'accept') {
                    $plagia_class = 'plagiarism_link_accept';
                    $plagiarism_count_accept++;
                    $plagiarism_rows .= '<div class="plagiarism_row"><div class="plagiarism_link ' . $plagia_class . '">' . $link . '</div></div>';
                } else if ($value == '?' || $value == 'error') {
                    $plagia_class = 'plagiarism_link_error';
                    $plagiarism_count_error++;
                    $plagiarism_rows .= '<div class="plagiarism_row"><div class="plagiarism_link ' . $plagia_class . '">' . $link . '</div></div>';
                } else {
                    $plagia_class = 'plagiarism_link_reject';
                    $plagiarism_count_reject++;
                    $plagiarism_rows .= '<div class="plagiarism_row"><div class="plagiarism_link ' . $plagia_class . '">' . $link . '</div></div>';
                }
            }
            if ($plagiarism_count_reject > 0) {
                echo '
			<div class="plagiarism_link_reject" style="font-size:125%; float:left; margin:5px; padding:5px;">' . $plagiarism_count_reject . ' REJECT</div>';
            }
            if ($plagiarism_count_accept > 0) {
                echo '
		  	<div class="plagiarism_link_accept" style="font-size:125%; float:left; margin:5px; padding:5px;">' . $plagiarism_count_accept . ' OK</div>';
            }
            if ($plagiarism_count_error > 0) {
                echo '
		  	<div class="plagiarism_link_error" style="font-size:125%; float:left; margin:5px; padding:5px;">' . $plagiarism_count_error . ' TODO</div>';
            }
            echo '<div style="clear: both;"></div>'
                . $plagiarism_rows;
        }

        if ($is_ajax) {
            exit();
        }
    }


    public function meta_inner_box()
    {

        echo '
	<div style="height:30px;">
	  <div style="line-height:24px;">
		<select name="plagiarism_search" id="plagiarism_search">
	        <option value="google"     ' . $plagiarism_google . '>' . __("Search Google Web") . '</option>
	        <option value="googleblog" ' . $plagiarism_blog . '>' . __("Search Google Blogs") . '</option>
	        <option value="yahoo"      ' . $plagiarism_yahoo . '>' . __("Search Yahoo") . '</option>
	        <option value="bing"       ' . $plagiarism_bing . '>' . __("Search Bing") . '</option>
	        <option value="bingblog"   ' . $plagiarism_bingblog . '>' . __("Search Bing Blogs") . '</option>
	        <option value="duckduckgo"   ' . $plagiarism_duckduckgo . '>' . __("Search DuckDuckGo Web") . '</option>
		</select>
	    <a id="plagiarism_check_button" class="button-primary" href="#nogo">' . __('Check for Duplicate Content', self::LANG) . '</a>
	    <a id="plagiarism_clear_button" class="button" href="#nogo">' . __('Clear Results', self::LANG) . '</a>
	    <img id="plagiarism_loader" src="' . plugins_url('assets/loader.gif', __FILE__) . '" />
	  </div>
	</div>
	<div id="plagiarism_meta_wrapper"> <!--begin target for ajax update -->';

        $this->plagiarism_search = $this->get_user_option('plagiarism_search');
        echo $this->render_meta_box();
        echo '</div><!-- end target for ajax update -->';
    }


// Returns bool == true if all chunks have been checked.
    public function check_post($post_id)
    {
        // verify if this is an auto save routine.
        // If it is our form has not been submitted, so we don't want to do anything

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return false;

        $is_ajax = ($_POST['plagiarism_ajax_call'] == 'true');

        $auto_check = ($this->get_user_option('plagiarism_auto_check') == 'on');

        if ((!$auto_check) && (!$is_ajax)) return false;

        $ajax_slice = $this->get_user_option('plagiarism_ajax_slice');

        if ($revision = wp_is_post_revision($post_id))
            $post_id = $revision;

        $orig_chunks = (array)(json_decode(base64_decode(get_post_meta($post_id, 'plagiarism_result', true))));

        $post = get_post($post_id);
        $body = $post->post_content;
        $body = do_shortcode($body); //expand any shortcodes
        $body = str_replace("&nbsp;", ' ', $body);
        $body = str_replace("<p>", ' <p>', $body);
        $body = str_replace("<h1>", ' <h1>', $body);
        $body = str_replace("<li>", ' <li>', $body);
        $body = str_replace("<ul>", ' <ul>', $body);
        $body = str_replace("&nbsp;", ' ', $body);
        $body = strip_tags($body);
        $chunks = $this->get_chunks($body);

        $num_chunks = count($chunks);
        if (count($orig_chunks) > 0) {
            $chunks = array_merge($chunks, $orig_chunks);
            $chunks = array_slice($chunks, 0, $num_chunks, true);
        }
        update_post_meta($post_id, 'plagiarism_result', base64_encode(json_encode($chunks)));

        $check_ok = true; //Assume OK
        foreach ($chunks as $key => $value) {
            if (in_array($value, array('?', 'error'))) {
                $this->current_search_key = $key;
                $value = $this->search($key);

                $chunks[$key] = $value;
                $ajax_slice--;
                if (get_user_option('plagiarism_debug') == 'on') break; // one at a time in debug
            }
            if (in_array($value, array('?', 'error', 'reject'))) $check_ok = false;  // Still not accepted
            if ($is_ajax && $ajax_slice == 0) break; //only do x at a time
        }
        update_post_meta($post_id, 'plagiarism_result', base64_encode(json_encode($chunks)));

        if ($check_ok) {
            update_post_meta($post_id, 'plagiarism_duplicate', 'false');
        } else {
            update_post_meta($post_id, 'plagiarism_duplicate', 'true');
        }

        return $check_ok;
    }


    public function get_chunks($body)
    {
        $n = intval($this->get_user_option('plagiarism_chunk_size'));
        $m = intval($this->get_user_option('plagiarism_chunk_step'));

        $words = preg_split("/[\s]+/u", $body);

        $chunks = array();
        $num_words = count($words);
        $num_chunks = ceil($num_words / $m);
        for ($i = 0; $i <= $num_words - $n; $i += $m) {
            $key = implode(" ", array_slice($words, $i, $n));

            if (str_word_count($key, 0) >= $n) {
                $chunks[$key] = '?';
            }
        }
        return $chunks;
    }

    public function build_query($phrase)
    {

        if ($this->plagiarism_search == 'google') {
            $result = self::GOOGLE_URL;
        } else if ($this->plagiarism_search == 'googleblog') {
            $result = self::GOOGLE_BLOG_URL;
        } else if ($this->plagiarism_search == 'yahoo') {
            $result = self::YAHOO_URL;
        } else if ($this->plagiarism_search == 'bing') {
            $result = self::BING_URL;
        } else if ($this->plagiarism_search == 'bingblog') {
            $result = self::BING_BLOG_URL;
        } else if ($this->plagiarism_search == 'duckduckgo') {
            $result = self::DUCKDUCKGO_URL;
        }

        $domains = explode("\n", $this->get_user_option('plagiarism_exclude_domains'));
        reset($domains);
        $q = '';
        foreach ($domains as $domain) {
            $domain = trim($domain);
            if ($domain != '') {
                $q .= " -site:$domain";
            }
        }

        $result .= urlencode('"' . $phrase . '"' . $q);
        return $result;
    }


    public function get_google_count($dom)
    {

        $resultStats = $dom->getElementById('resultStats');
        $docs_found = false;
        $text = '';
        $count = 0;
        if (!is_null($resultStats)) {
            $text = $resultStats->textContent;
            $docs_found = trim($text) != '';
        }

        foreach ($dom->getElementsByTagName('input') as $input) {
            $input->parentNode->removeChild($input);
        }
        foreach ($dom->getElementsByTagName('title') as $input) {
            $input->parentNode->removeChild($input);
        }
        foreach ($dom->getElementsByTagName('form') as $input) {
            $input->parentNode->removeChild($input);
        }
        $text2 = $dom->saveHTML();

        $text2 = preg_replace("/<img[^>]+\>/i", "(image) ", $text2);

        if (strpos($text2, 'No results found for') !== false || stripos($text2, $this->current_search_key) === false) {
            return 0;
        }

       return 1;
    }

public function get_duckduckgo_count($dom)
    {


        foreach ($dom->getElementsByTagName('input') as $input) {
            $input->parentNode->removeChild($input);
        }
        foreach ($dom->getElementsByTagName('title') as $input) {
            $input->parentNode->removeChild($input);
        }
        foreach ($dom->getElementsByTagName('form') as $input) {
            $input->parentNode->removeChild($input);
        }
        $text2 = $dom->saveHTML();

        $text2 = preg_replace("/<img[^>]+\>/i", "(image) ", $text2);
        $text2 = preg_replace("/<input[^>]+\>/i", "(input) ", $text2);

        if (strpos($text2, 'No  results.') !== false || stripos($text2, $this->current_search_key) === false) {
            return 0;
        }

       return 1;
    }


    public function get_yahoo_count($dom)
    {

        $docs_found = false;
        $text = '';
        $count = 0;

        $txt = $dom->saveHTML();

        if (stripos($txt, 'We did not find results') !== false) {
            return 0;
        }

        return 1;

        $result_count = $dom->getElementById('resultCount');

        if (isset($result_count)) {
            $text = $result_count->textContent;
            $docs_found = trim($text) != '';
        } else {
            $xpath = new DOMXPath($dom);
            $node = $xpath->query('//span[@class="count"]');
            $text = $node->item(0)->textContent;
            unset($xpath);
        }

        if ($text != '') {
            $docs_found = trim($text) != '';
        }

        if ($docs_found) {
            $text = preg_replace('/\D+/mu', '', $text);
            if (is_numeric($text)) {
                $count = intval($text);
            }
        }
        return $count;
    }

    public function get_bing_count($dom)
    {

        $docs_found = false;
        $text = '';
        $count = 0;
        $result_count = $dom->getElementById('count');

        if (isset($result_count)) {
            $text = $result_count->textContent;
        }
        $xpath = new DOMXPath($dom);
        $node = $xpath->query('//div[@class="autospell sph"]');

        $txt = $dom->saveHTML();

        if (stripos($txt, 'There are no results for') !== false) {
            $text = '';
            return 0;
        }
        unset($xpath);

        if ($text != '') {
            $t = explode("of", $text);
            $text = $t[1];
            $docs_found = trim($text) != '';
        }

        if ($docs_found) {
            $text = preg_replace('/\D+/mu', '', $text);
            if (is_numeric($text)) {
                $count = intval($text);
            }
        }
        return $count;
    }

    public function get_bing_blog_count($dom)
    {

        $docs_found = false;
        $text = '';
        $count = 0;
        $result_count = $dom->getElementById('count');

        if (isset($result_count)) {
            $text = $result_count->textContent;
        } else {
            $xpath = new DOMXPath($dom);
            $node = $xpath->query('//span[@class="ResultsCount"]');
            $text = $node->item(0)->textContent;
            unset($xpath);
        }

        if ($text != '') {
            $t = explode('of', $text);
            $text = $t[1];
            $docs_found = trim($text) != '';
        }

        if ($docs_found) {
            $text = preg_replace('/\D+/mu', '', $text);
            if (is_numeric($text)) {
                $count = intval($text);
            }
        }
        return $count;
    }

    public function get_count($dom)
    {
        if ($this->plagiarism_search == 'google') {
            return $this->get_google_count($dom);
        } else if ($this->plagiarism_search == 'googleblog') {
            return $this->get_google_count($dom);
        } else if ($this->plagiarism_search == 'yahoo') {
            return $this->get_yahoo_count($dom);
        } else if ($this->plagiarism_search == 'bing') {
            return $this->get_bing_count($dom);
        } else if ($this->plagiarism_search == 'bingblog') {
            return $this->get_bing_blog_count($dom);
        } else if ($this->plagiarism_search == 'duckduckgo') {
            return $this->get_duckduckgo_count($dom);
        }
    }

    public function search($query)
    {

        $this->current_search_result = '';

        $url = $this->build_query($query);
        $docs_found = false;
        $loaded = false;
        $is_ajax = ($_POST['plagiarism_ajax_call'] == 'true');

        $body = '';

        sleep((int)($this->get_user_option('plagiarism_sleep')));

        //Proxies
        if (get_user_option('plagiarism_use_proxy') == 'on') {
            $proxies = $this->get_proxies();
            $current_proxy = intval($this->get_user_option('plagiarism_current_proxy'));
            if (count($proxies) > 0) {
                //Next proxy
                $this->update_user_option('plagiarism_current_proxy', strval(intval($this->get_user_option('plagiarism_current_proxy') + 1) % count($proxies)));

                print_r($proxies[$current_proxy]);
                echo '<br/>';
                echo 'Trying Proxy=' . $proxies[$current_proxy][host] . ':' . $proxies[$current_proxy][port] . '<br />';

                define('WP_PROXY_HOST', $proxies[$current_proxy][host]);
                define('WP_PROXY_PORT', $proxies[$current_proxy][port]);

                if ($proxies[$current_proxy][user] != '' && $proxies[$current_proxy][pass] != '') {
                    define('WP_PROXY_USERNAME', $proxies[$current_proxy][user]);
                    define('WP_PROXY_PASSWORD', $proxies[$current_proxy][pass]);
                }
                define('WP_PROXY_BYPASS_HOSTS', 'localhost');
            }
        }

        $response = wp_remote_request($url, array('user-agent' => self::USER_AGENT));

        if (is_wp_error($response)) {
            echo "<p>" . $response->get_error_message() . "</p>\n";
            exit();
        } else {
            $loaded = ($response['response']['code'] == '200');
            $body = $response['body'];
            if (!$loaded) {
                echo "<p>" . $response['response']['code'] . "&mdash;" . $response['response']['message'] . "</p>\n";
            }
        }

        //show debug button
        if (get_user_option('plagiarism_debug') == 'on') {

            echo '<div id="plagiarismDebug" style="display:none;">';
            echo '<form style="width:100%; height:95%;"><textarea style="width:640px; height:95%;">' . htmlentities($body) . "</textarea></form>\n";
            echo '</div>';

            echo '<p><input id="plagiarism_debug_button" alt="' . self::THICKBOX_INLINE
                . '" class="thickbox button" type="button" title="'
                . __('Response Code: ', self::LANG) . $response['response']['code']
                . __(' &mdash; Message: ', self::LANG) . $response['response']['message']
                . '" value="' . __('CLICK FOR DEBUG SCREEN', self::LANG) . '" /></p>';
        }

        if ($is_ajax && (stripos($response['body'], 'action="captcha"') > 0)) {
            _e('<p>The search engine is currently refusing searches. You may have done too many searches too quickly or your hosting company may have others on your domain that are abusing searches. Try again later or switch to a different search engine.</p>', self::LANG);
            exit();
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->encoding = 'UTF-8';
        if ($loaded) {
            $dom->loadHTML($body);
        }
        libxml_clear_errors();


        if (!$loaded) {
            $result = 'error';
            unset($dom);
            echo $response['response']['code'];
            return $result;
        }

        $this->current_search_result = $body;
        $count = $this->get_count($dom);
        unset($request);
        unset($dom);

        $result = ($count > 0) ? 'reject' : 'accept';
        return $result;
    }


    public function admin_options()
    {
        $uid = get_current_user_id();

        if ($_POST['plagiarism_ispost'] == 'true') {
            //Form data sent
            check_admin_referer('plagiarism-settings');
            if (is_numeric($_POST['plagiarism_chunk_size'])) {
                $plagiarism_chunk_size = $_POST['plagiarism_chunk_size'];
                update_user_option($uid, 'plagiarism_chunk_size', $plagiarism_chunk_size, true);
            }
            if (is_numeric($_POST['plagiarism_chunk_size'])) {
                $plagiarism_chunk_step = $_POST['plagiarism_chunk_step'];
                update_user_option($uid, 'plagiarism_chunk_step', $plagiarism_chunk_step, true);
            }
            if (is_numeric($_POST['plagiarism_ajax_slice'])) {
                $plagiarism_ajax_slice = $_POST['plagiarism_ajax_slice'];
                update_user_option($uid, 'plagiarism_ajax_slice', $plagiarism_ajax_slice, true);
            }
            if (is_numeric($_POST['plagiarism_sleep'])) {
                $plagiarism_sleep = $_POST['plagiarism_sleep'];
                update_user_option($uid, 'plagiarism_sleep', $plagiarism_sleep, true);
            }

            $plagiarism_exclude_domains = $_POST['plagiarism_exclude_domains'];
            update_user_option($uid, 'plagiarism_exclude_domains', $plagiarism_exclude_domains, true);

            $plagiarism_proxies = $_POST['plagiarism_proxies'];
            update_user_option($uid, 'plagiarism_proxies', $plagiarism_proxies, true);

            if ($_POST['plagiarism_auto_check'] == 1) {
                update_user_option($uid, 'plagiarism_auto_check', 'on', true);
                $plagiarism_auto_check = 'checked="on"';
            } else {
                update_user_option($uid, 'plagiarism_auto_check', 'off', true);
                $plagiarism_auto_check = '';
            }

            if ($_POST['plagiarism_editor_required'] == 1) {
                update_option('plagiarism_editor_required', 'on');
                $plagiarism_editor_required = 'checked="checked"';
            } else {
                update_option('plagiarism_editor_required', 'off');
                $plagiarism_editor_required = '';
            }

            if ($_POST['plagiarism_debug'] == 1) {
                update_user_option($uid, 'plagiarism_debug', 'on', true);
                $plagiarism_debug = 'checked="on"';
            } else {
                update_user_option($uid, 'plagiarism_debug', 'off', true);
                $plagiarism_debug = '';
            }

            if ($_POST['plagiarism_use_proxy'] == 1) {
                update_user_option($uid, 'plagiarism_use_proxy', 'on', true);
                $plagiarism_use_proxy = 'checked="on"';
            } else {
                update_user_option($uid, 'plagiarism_use_proxy', 'off', true);
                $plagiarism_use_proxy = '';
            }

            echo '<div class="updated settings-error"><p><strong>';
            _e('Settings Updated');
            echo '</strong></p></div>';
        } else {

            //PAGE DISPLAY
            $plagiarism_chunk_size = get_user_option('plagiarism_chunk_size');
            $plagiarism_chunk_step = get_user_option('plagiarism_chunk_step');
            $plagiarism_ajax_slice = get_user_option('plagiarism_ajax_slice');
            $plagiarism_sleep = get_user_option('plagiarism_sleep');
            $plagiarism_exclude_domains = get_user_option('plagiarism_exclude_domains');
            $plagiarism_proxies = get_user_option('plagiarism_proxies');
        }

        $plagiarism_auto_check = (get_user_option('plagiarism_auto_check') == 'on') ? 'checked="checked"' : '';
        $plagiarism_editor_required = (get_option('plagiarism_editor_required') == 'on') ? 'checked="checked"' : '';
        $plagiarism_debug = (get_user_option('plagiarism_debug') == 'on') ? 'checked="checked"' : '';
        $plagiarism_use_proxy = (get_user_option('plagiarism_use_proxy') == 'on') ? 'checked="checked"' : '';
        ?>

        <div class="wrap">
        <div id="icon-themes" class="icon32"><br></div>
        <h2><?php _e('Plagiarism Options', LANG); ?></h2>
        <div id="poststuff" style="padding-top:10px; position:relative;">
        <div style="float:left; width:74%; padding-right:1%;">

            <?php
            $info = '
	  <div>
		    <p>' . __("Plagiarism allows you to search the internet for phrases you may have inadvertantly
		      duplicated in your posts and pages. Google, since the Panda update, has been giving such duplicate content a
		      very low ranking.") . '
		    </p>
		    <p>
		      ' . __("Plagiarism also allows you to search for copies of your own posts and pages,
		      identifying posts that may have been copied and posted elsewhere on the internet.") . '
		    </p>		
		  </div>
		
		  <div>
		    <p>
		      ' . __('The results list will show the phrase used for the search and their status.
		      Clicking the phrase will open a window to the search so you can see the sites that were searched.') . '
		      <ul style="padding-left:40px">
		        <li class="plagiarism_link_accept"> ' . __('Phrase accepted.') . '</li>
		        <li class="plagiarism_link_reject"> ' . __('Phrase found on the web. Check searched sites for duplicate content') . '</li>
		        <li class="plagiarism_link_error" > ' . __('Phrase not yet searched. Click again the "Check for Duplicate Content" button to search another slice of phrases') . '</li>
		      </ul>
		    </p>
		    <p>
		      ' . __("Setting the Editor Required box will require that posts with duplicate content only be published by an Editor or Administrator.
		      Otherwise it is saved with a Pending Review status if the Publish button is clicked. ") . '
		    </p>
		  </div>
';
            echo $this->box_content(__('Plugin Info'), $info);
            ?>

            <form name="plagiarism_admin_form" id="plagiarism_admin_form" method="post"
                  action="<?php echo str_replace('%7E', '~', $_SERVER['REQUEST_URI']); ?>">
                <?php wp_nonce_field('plagiarism-settings'); ?>

                <input type="hidden" name="plagiarism_ispost" value="true">
                <?php
                $search_options = array(
                    __('Phrase chunk size') => '<input type="text" name="plagiarism_chunk_size" value="' . $plagiarism_chunk_size . '" size="10"> 
					<span class="description">' . __(" (default 10) Size of the phrases to be extracted") . '</span>',
                    __('Phrase chunk step') => '<input type="text" name="plagiarism_chunk_step" value="' . $plagiarism_chunk_step . '" size="10"> 
					<span class="description">' . __(" (default 6) Size of the offset before the next phrase is chosen") . '</span>',
                    __('Query slice size') => '<input type="text" name="plagiarism_ajax_slice" value="' . $plagiarism_ajax_slice . '" size="10"> 
					<span class="description">' . __(' (default 10) Number of searches per click.<br />
					Don\'t set the Query slices to high, otherwhise the search engines 
				      may stop returning results for a while. It\'s best to turn auto search off while doing heavy
				      edits and saving drafts and then search when the post is ready to be published.') . '</span>',
                    __('Query wait time') => '<input type="text" name="plagiarism_sleep"      value="' . $plagiarism_sleep . '" size="10"> 
					<span class="description">' . __(" (default 0) Number of seconds to wait between searches") . '</span>',
                );
                $general_options = array(
                    __('Block duplicate content') => '<input type="checkbox" name="plagiarism_editor_required" value="1" ' . $plagiarism_editor_required . '>
					<span class="description">' . __('Editor or Administrator role Required to Publish posts with duplicate content') . '</span>',
                    __('Search on save') => '<input type="checkbox" name="plagiarism_auto_check" value="1" ' . $plagiarism_auto_check . '>
					<span class="description">' . __('Automatically search on save. Search when saving a Draft or Publishing') . '</span>',
                    __('Debug mode') => '<input type="checkbox" name="plagiarism_debug" value="1" ' . $plagiarism_debug . '>',
                    __('Excluded Domains') => __("Exclude these domains from searches: (example ") . parse_url(home_url(), PHP_URL_HOST) . ' ), ' . __('one domain per line') . '<br />
			        <textarea name="plagiarism_exclude_domains" rows="5" cols="60">' . $plagiarism_exclude_domains . '</textarea><br />
			        <span class="description">' . __("Used to avoid searching sites where you've syndicated your posts and the originating site.") . '</span>',
                );
                $proxies_options = array(
                    __('Use Proxies') => '<input type="checkbox" name="plagiarism_use_proxy" value="1" ' . $plagiarism_use_proxy . '> ' . __("Use the proxy list below for searches"),
                    __('Proxy list') => __('You can try using free proxies but be warned  
					that they are typically slow. If you have your own list of private proxies 
					they will be rotated for each search so heavy hits from one IP address will be spread out.
					<br /><br />
					Insert one proxy per line, as USER:PASSWORD@IP:PORT (example myName:myPassword@88.208.107.2:8080)') . '<br />
			        <textarea name="plagiarism_proxies" rows="6" cols="60">' . $plagiarism_proxies . '</textarea><br />'
                        . __('If USER and PASSWORD are not required just enter the IP:PORT without the "@"'),
                );

                echo $this->box_content(__('Search Options'), $search_options);
                echo $this->box_content(__('General Options'), $general_options);
                echo $this->box_content(__('Proxies Options'), $proxies_options);
                ?>
                <p class="submit"><input type="submit" class="button-primary" name="Submit"
                                         value="<?php _e('Update Options', LANG) ?>"/></p>
            </form>
        </div>

        <div style="float:right; width:25%;">
        <?php
        ob_start();
        $this->feed_box();
        $feed_box = ob_get_clean();

        echo $this->box_content('A Free Plugin by PMI Servizi', '
		<a target="_blank" href="http://www.pmiservizi.it/web-agency.html" title="PMI Servizi Web Agency">
			<img border="0" src="' . plugin_dir_url(__FILE__) . 'assets/pmiservizi_logo.png" title="PMI Servizi Web Agency" alt="PMI Servizi Web Agency" style="display:block; margin:10px auto;">
		</a>
	')
            . $this->box_content('Spread the love!', '
		Do you like our plugin?<br />
		Rate it!<br /><br />
	')
            . $this->box_content('Support?', '
		Most of the actual plugin features were requested by users and developed for the sake of doing it.<br /><br />
		<b>Do you have any suggestions or requests?</b><br />
		Tell us about it in the support forum or using our 
		<a href="http://www.pmiservizi.it/contatti.html" target="_blank" title="PMI Servizi - Contact us">website form</a>.

	')
            . $this->box_content('SEO News by Plagiarism', $feed_box)
            . '
		</div>
	</div>
	</div>';
    }


    function box_content($title, $content)
    {
        if (is_array($content)) {
            $content_string = '<table>';
            foreach ($content as $name => $value) {
                $content_string .= '<tr>
				<td style="width:130px; vertical-align: text-top;">' . __($name, 'menu-test') . ':</td>	
				<td>' . $value . '</td>
				</tr>';
            }
            $content_string .= '</table>';
        } else {
            $content_string = $content;
        }

        $out = '
		<div class="postbox">
			<div class="inside"><h3>' . __($title, 'menu-test') . '</h3>
			' . $content_string . '</div>
		</div>
		';
        return $out;
    }

    function get_options_default()
    {
        $option = array();

        return $option;
    }

} // END PLAGIARISM CLASS


add_filter('manage_posts_columns', 'plagma_column_views');
add_filter('manage_pages_columns', 'plagma_column_views');
add_action('manage_posts_custom_column', 'plagma_custom_column_views', 6, 2);
add_action('manage_pages_custom_column', 'plagma_custom_column_views', 6, 2);
add_action('admin_head', 'plagma_column_style');
add_filter('manage_edit-post_sortable_columns', 'plagma_manage_sortable_columns');
add_filter('manage_edit-page_sortable_columns', 'plagma_manage_sortable_columns');
add_action('pre_get_posts', 'plagma_pre_get_posts', 1);
add_action('pre_get_pages', 'plagma_pre_get_posts', 1);

function plagma_column_views($defaults)
{
    $defaults['post_plagerism_pct'] = __('Plagerism Warnings');
    return $defaults;
}

function plagma_custom_column_views($column_name, $id)
{

    $stats = "NA";
    $chunks = json_decode(base64_decode(get_post_meta($id, 'plagiarism_result', true)));
    $cnt = 0;
    $plagiarism_count_accept = 0;
    $plagiarism_count_reject = 0;
    $plagiarism_count_error = 0;
    if (count($chunks) > 0) {

        foreach ($chunks as $key => $value) {
            if ($value == 'accept') {
                $plagiarism_count_accept++;
            } else if ($value == '?' || $value == 'error') {
                $plagiarism_count_error++;
            } else {
                $plagiarism_count_reject++;
            }
        }
        $cnt = ($plagiarism_count_reject);
        $stats = ($cnt) . " Found";
    }

    if ($stats == "0" || $stats == "NA") {
        if ($column_name === 'post_plagerism_pct') {
            echo "<div area-hidden='true' title='clean' class='wpseo-score-icon na'></div> Not Checked";
            return 0;
        }
    }

    if ($column_name === 'post_plagerism_pct') {
        /*$show_text = "<div area-hidden='true' title='clean' class='wpseo-score-icon bad'></div>" . $cnt;

        if ($cnt === 0) {
            $show_text = "<div area-hidden='true' title='clean' class='wpseo-score-icon good'></div> Clean";
        }

        if ($plagiarism_count_error > 0) {
            $show_text = "<div area-hidden='true' title='clean' class='wpseo-score-icon ok'></div> $plagiarism_count_error Todo";
        }*/

        $show_text = "<div area-hidden='true' title='clean' class='wpseo-score-icon good'></div>$plagiarism_count_accept <div area-hidden='true' title='clean' class='wpseo-score-icon bad'></div>$plagiarism_count_reject <div area-hidden='true' title='clean' class='wpseo-score-icon ok'></div>$plagiarism_count_error";


        echo $show_text;
        return $cnt;
    }

}


function plagma_column_style()
{

    echo '<style>.column-post_plagerism_pct { min-width: 150px; }</style>';

}

function plagma_manage_sortable_columns($sortable_columns)
{

    $sortable_columns['post_plagerism_pct'] = 'post_plagerism_pct';

    return $sortable_columns;

}

function plagma_pre_get_posts($query)
{

    if ($query->is_main_query() && ($orderby = $query->get('orderby'))) {
        switch ($orderby) {
            case 'post_plagerism_pct':
                $query->set('meta_key', '_plagma_post_plagerism_pct');
                $query->set('orderby', 'meta_value_num');
                break;
        }
    }

    return $query;

}


