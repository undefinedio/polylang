<?php 
/*
Plugin Name: Polylang
Plugin URI: http://wordpress.org/extend/plugins/polylang/
Version: 0.5
Author: F. Demarle
Description: Adds multilingual capability to Wordpress
*/

/*  Copyright 2011 F. Demarle

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

define('POLYLANG_VERSION', '0.5');

define('POLYLANG_DIR', dirname(__FILE__));
define('PLL_INC', POLYLANG_DIR.'/include');

if (!defined('PLL_DISPLAY_ALL'))
	define('PLL_DISPLAY_ALL', false); // diplaying posts & terms with undefined language is disabled by default

require_once(PLL_INC.'/base.php');
require_once(PLL_INC.'/widget.php');
require_once(PLL_INC.'/calendar.php');

// controls the plugin, deals with activation, deactivation, upgrades, initialization as well as rewrite rules
class Polylang extends Polylang_Base {

	function __construct() {
		global $polylang;

		// manages plugin activation and deactivation
		register_activation_hook( __FILE__, array(&$this, 'activate') );
		register_deactivation_hook( __FILE__, array(&$this, 'deactivate') );

		// manages plugin upgrade
		add_filter('upgrader_pre_install', array(&$this, 'pre_upgrade'));
		add_filter('upgrader_post_install', array(&$this, 'post_upgrade'));
		add_action('admin_init',  array(&$this, 'admin_init'));

		// plugin and widget initialization
		add_action('init', array(&$this, 'init'));
		add_action('widgets_init', array(&$this, 'widgets_init'));

		// rewrite rules
		add_filter('rewrite_rules_array', array(&$this, 'rewrite_rules_array' ));

		if (is_admin()) {
			require_once(PLL_INC.'/admin.php');
			new Polylang_Admin();
		}
		else {
			require_once(PLL_INC.'/core.php');
			require_once(PLL_INC.'/api.php');
			$polylang = new Polylang_Core();
		}
	}

	// plugin activation for multisite
	function activate() {
		global $wpdb;

		// check if it is a network activation - if so, run the activation function for each blog
		if (is_multisite() && isset($_GET['networkwide']) && ($_GET['networkwide'] == 1)) {
			foreach ($wpdb->get_col($wpdb->prepare("SELECT blog_id FROM $wpdb->blogs")) as $blog_id) {
				switch_to_blog($blog_id);
				$this->_activate();
			}
			restore_current_blog();
		}
		else
			$this->_activate();
	}

	// plugin activation
	function _activate() {
		// create the termmeta table - not provided by WP by default - if it does not already exists
		// uses exactly the same model as other meta tables to be able to use access functions provided by WP 
		global $wpdb;
		$charset_collate = '';  
		if ( ! empty($wpdb->charset) )
		  $charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
		if ( ! empty($wpdb->collate) )
		  $charset_collate .= " COLLATE $wpdb->collate";

		$table = $wpdb->prefix . 'termmeta';
		
		$tables = $wpdb->get_results("show tables like '$table'");
		if (!count($tables))
		  $wpdb->query("CREATE TABLE $table (
		    meta_id bigint(20) unsigned NOT NULL auto_increment,
		    term_id bigint(20) unsigned NOT NULL default '0',
		    meta_key varchar(255) default NULL,
		    meta_value longtext,
		    PRIMARY KEY  (meta_id),
		    KEY term_id (term_id),
		    KEY meta_key (meta_key)
		  ) $charset_collate;");

		// codex tells to use the init action to call register_taxonomy but I need it now for my rewrite rules
		register_taxonomy('language', get_post_types(array('show_ui' => true)), array('label' => false, 'query_var'=>'lang')); 

		// defines default values for options in case this is the first installation
		$options = get_option('polylang');
		if (!$options) {
			$options['browser'] = 1; // default language for the front page is set by browser preference
			$options['rewrite'] = 0; // do not remove /language/ in permalinks
			$options['hide_default'] = 0; // do not remove URL language information for default language
		}
		$options['version'] = POLYLANG_VERSION;
		update_option('polylang', $options);

		// add our rewrite rules
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
	}

	// plugin deactivation for multisite
	function deactivate() {
		global $wpdb;

		// check if it is a network deactivation - if so, run the deactivation function for each blog
		if (is_multisite() && isset($_GET['networkwide']) && ($_GET['networkwide'] == 1)) {
			foreach ($wpdb->get_col($wpdb->prepare("SELECT blog_id FROM $wpdb->blogs")) as $blog_id) {
				switch_to_blog($blog_id);
				$this->_deactivate();
			}
			restore_current_blog();
		}
		else
			$this->_deactivate();
	}

	// plugin deactivation
	function _deactivate() {
		global $wp_rewrite;

		// delete our rewrite rules
		remove_filter('rewrite_rules_array', array(&$this,'rewrite_rules_array' ));
		$wp_rewrite->flush_rules();
	}

	// saves the local_flags directory before upgrade
	function pre_upgrade() {
		// nothing to backup
		if (!@is_dir($flags_dir = POLYLANG_DIR . '/local_flags'))
			return true;
	
		$upgrade_dirs = array(
			WP_CONTENT_DIR . '/upgrade',
			WP_CONTENT_DIR . '/upgrade/polylang' 
		);

		foreach ($upgrade_dirs as $dir) {	
			if (!@is_dir($dir) && !@mkdir($dir, 0755))
				return new WP_Error('polylang_upgrade_error', sprintf('%s<br />%s <strong>%s</strong>',
					__("Error: Upgrade directory doesn't exist!", 'polylang'),
					__('Please create', 'polylang'),
					esc_html($dir)
				));
		}

		if (!@rename($flags_dir, WP_CONTENT_DIR . '/upgrade/polylang/local_flags'))
			return new WP_Error('polylang_backup_error', sprintf('%s<br />%s <strong>%s</strong>',
				__('Error: Backup of local flags failed!', 'polylang'),
				__('Please backup', 'polylang'),
				esc_html($flags_dir)
			));

		return true;
	}

	// restores the local_flags directory after upgrade
	function post_upgrade() {
		// nothing to restore
		if (!@is_dir($upgrade_dir = WP_CONTENT_DIR . '/upgrade/polylang/local_flags'))
			return true;

		if (!@rename($upgrade_dir, POLYLANG_DIR . '/local_flags'))
			return new WP_Error('polylang_restore_error', sprintf('%s<br />%s (<strong>%s</strong>)',
				__('Error: Restore of local flags failed!', 'polylang'),
				__('Please restore your local flags', 'polylang'),
				esc_html($upgrade_dir)
			));

		@rmdir(WP_CONTENT_DIR . '/upgrade/polylang');
		return true;
	}

	// upgrades from old translation used up to V0.4.4 to new model used in V0.5+ 
	function upgrade_translations($type, $ids) {
		$listlanguages = $this->get_languages_list();
		foreach ($ids as $id) {
			$lang = call_user_func(array(&$this, 'get_'.$type.'_language'), $id);
			if (!$lang)
				continue;

			$tr = array();
			foreach ($listlanguages as $language) {
				if ($meta = get_metadata($type, $id, '_lang-'.$language->slug, true))
					$tr[$language->slug] = $meta;
			}

			if(!empty($tr)) {
				$tr = serialize(array_merge(array($lang->slug => $id), $tr));
				update_metadata($type, $id, '_translations', $tr);
			}
		}
	}

	// manage upgrade even when it is done manually
	function admin_init() {
		$options = get_option('polylang');
		if (version_compare($options['version'], POLYLANG_VERSION, '<')) {

			if (version_compare($options['version'], '0.4', '<'))
				$options['hide_default'] = 0; // option introduced in 0.4

			// translation model changed in 0.5
			// FIXME will not delete old data before 0.6 (just in case...)
			if (version_compare($options['version'], '0.5', '<')) {
				$ids = get_posts(array('numberposts'=>-1, 'fields' => 'ids', 'post_type'=>'any', 'post_status'=>'any'));
				$this->upgrade_translations('post', $ids);
				$ids = get_terms(get_taxonomies(array('show_ui'=>true)), array('get'=>'all', 'fields'=>'ids'));
				$this->upgrade_translations('term', $ids);	
			}

			$options['version'] = POLYLANG_VERSION;
			update_option('polylang', $options);
		}
	}

	// some initialization
	function init() {
		global $wpdb;
		$wpdb->termmeta = $wpdb->prefix . 'termmeta'; // registers the termmeta table in wpdb

		// registers the language taxonomy
		// codex: use the init action to call this function
		register_taxonomy('language', get_post_types(array('show_ui' => true)), array(
			'label' => false,
			'public' => false, // avoid displaying the 'like post tags text box' in the quick edit
			'query_var'=>'lang',
			'update_count_callback' => '_update_post_term_count'));

		// optionaly removes 'language' in permalinks so that we get http://www.myblog/en/ instead of http://www.myblog/language/en/
		// the simple line of code is inspired by the WP No Category Base plugin: http://wordpresssupplies.com/wordpress-plugins/no-category-base/
		global $wp_rewrite;
		$options = get_option('polylang');
		if ($options['rewrite'] && $wp_rewrite->extra_permastructs)	
			$wp_rewrite->extra_permastructs['language'][0] = '%language%';

		load_plugin_textdomain('polylang', false, basename(POLYLANG_DIR).'/languages'); // plugin i18n
	}

	// registers our widgets
	function widgets_init() {
		register_widget('Polylang_Widget');

		// overwrites the calendar widget to filter posts by language
  	unregister_widget('WP_Widget_Calendar');
  	register_widget('Polylang_Widget_Calendar');
	}

	// rewrites rules if pretty permalinks are used
	function rewrite_rules_array($rules) {
		$options = get_option('polylang');
		$newrules = array();

		$listlanguages = $this->get_languages_list();

		// modifies the rules created by WordPress when '/language/' is removed in permalinks
		if ($options['rewrite']) {					
			foreach ($listlanguages as $language) {
				$slug = $options['default_lang'] == $language->slug && $options['hide_default'] ? '' : $language->slug . '/';
				$newrules[$slug.'feed/(feed|rdf|rss|rss2|atom)/?$'] = 'index.php?lang='.$language->slug.'&feed=$matches[1]';
				$newrules[$slug.'(feed|rdf|rss|rss2|atom)/?$'] = 'index.php?lang='.$language->slug.'&feed=$matches[1]';
				$newrules[$slug.'page/?([0-9]{1,})/?$'] = 'index.php?lang='.$language->slug.'&paged=$matches[1]';
				if ($slug)
					$newrules[$slug.'?$'] = 'index.php?lang='.$language->slug;
			}
			unset($rules['([^/]+)/feed/(feed|rdf|rss|rss2|atom)/?$']); // => index.php?lang=$matches[1]&feed=$matches[2]
			unset($rules['([^/]+)/(feed|rdf|rss|rss2|atom)/?$']); // => index.php?lang=$matches[1]&feed=$matches[2]
			unset($rules['([^/]+)/page/?([0-9]{1,})/?$']); // => index.php?lang=$matches[1]&paged=$matches[2]
			unset($rules['([^/]+)/?$']); // => index.php?lang=$matches[1]
		}

		$base = $options['rewrite'] ? '' : 'language/';			

		// rewrite rules for comments feed filtered by language
		foreach ($listlanguages as $language) {
			$slug = $options['default_lang'] == $language->slug && $options['hide_default'] ? '' : $base.$language->slug . '/';
			$newrules[$slug.'comments/feed/(feed|rdf|rss|rss2|atom)/?$'] = 'index.php?lang='.$language->slug.'&feed=$matches[1]&withcomments=1';
			$newrules[$slug.'comments/(feed|rdf|rss|rss2|atom)/?$'] = 'index.php?lang='.$language->slug.'&feed=$matches[1]&withcomments=1';
		}
		unset($rules['comments/feed/(feed|rdf|rss|rss2|atom)/?$']); // => index.php?&feed=$matches[1]&withcomments=1
		unset($rules['comments/(feed|rdf|rss|rss2|atom)/?$']); // => index.php?&feed=$matches[1]&withcomments=1

		// rewrite rules for archives filtered by language
		foreach ($rules as $key => $rule) {
			$is_archive = strpos($rule, 'author_name=') || strpos($rule, 'year=') && !(
				strpos($rule, 'p=') ||
				strpos($rule, 'name=') ||
				strpos($rule, 'page=') ||
				strpos($rule, 'cpage=') );

			if ($is_archive) {
				foreach ($listlanguages as $language) {
					$slug = $options['default_lang'] == $language->slug && $options['hide_default'] ? '' : $base.$language->slug . '/';
					$newrules[$slug.$key] = str_replace('?', '?lang='.$language->slug.'&', $rule);
				}
				unset($rules[$key]); // now useless
			}
		}
		return $newrules + $rules;
	}

} // class Polylang

if (class_exists("Polylang"))
	new Polylang();

?>
