<?php

/*
 * manages custom menus translations as well as the language switcher menu item on frontend
 *
 * @since 1.2
 */
class PLL_Frontend_Nav_Menu {

	/*
	 * constructor
	 *
	 * @since 1.2
	 */
	public function __construct() {
		// split the language switcher menu item in several language menu items
		add_filter('wp_get_nav_menu_items', array(&$this, 'wp_get_nav_menu_items'));
		add_filter('wp_nav_menu_objects', array(&$this, 'wp_nav_menu_objects'));
		add_filter('nav_menu_link_attributes', array(&$this, 'nav_menu_link_attributes'), 10, 3);

		// filters menus locations by language
		add_filter('get_nav_menu', array($this, 'get_nav_menu'), 1);
	}

	/*
	 * splits the one item of backend in several items on frontend
	 * take care to menu_order as it is used later in wp_nav_menu
	 *
	 * @since 1.1.1
	 *
	 * @param array $items menu items
	 * @return array modified items
	 */
	public function wp_get_nav_menu_items($items) {
		$new_items = array();
		$offset = 0;

		foreach ($items as $key => $item) {
			if ($options = get_post_meta($item->ID, '_pll_menu_item', true)) {
				extract($options);
				$i = 0;

				foreach (pll_the_languages(array_merge(array('raw' => 1), $options)) as $language) {
					extract($language);
					$lang_item = clone $item;
					$lang_item->title = $show_flags && $show_names ? $flag.'&nbsp;'.$name : ($show_flags ? $flag : $name);
					$lang_item->url = $url;
					$lang_item->lang = $slug; // save this for use in nav_menu_link_attributes
					$lang_item->classes = $classes;
					$lang_item->menu_order += $offset + $i++;
					$new_items[] = $lang_item;
				}
				$offset += $i - 1;
			}
			else {
				$item->menu_order += $offset;
				$new_items[] = $item;
			}
		}

		return $new_items;
	}

	/*
	 * returns the ancestors of a menu item
	 *
	 * @since 1.1.1
	 *
	 * @param object $item
	 * @return array ancestors ids
	 */
	public function get_ancestors($item) {
		$ids = array();
		$_anc_id = (int) $item->db_id;
		while(($_anc_id = get_post_meta($_anc_id, '_menu_item_menu_item_parent', true)) && !in_array($_anc_id, $ids))
			$ids[] = $_anc_id;
		return $ids;
	}

	/*
	 * removes current-menu and current-menu-ancestor classes to lang switcher when not on the home page
	 *
	 * @since 1.1.1
	 *
	 * @param array $items
	 * @return array modified menu items
	 */
	public function wp_nav_menu_objects($items) {
		$r_ids = $k_ids = array();

		foreach ($items as $item) {
			if (is_array($item->classes) && in_array('current-lang', $item->classes)) {
				$item->classes = array_diff($item->classes, array('current-menu-item'));
				$r_ids = array_merge($r_ids, $this->get_ancestors($item)); // remove the classes for these ancestors
			}
			elseif (is_array($item->classes) && in_array('current-menu-item', $item->classes))
				$k_ids = array_merge($k_ids, $this->get_ancestors($item)); // keep the classes for these ancestors
		}

		$r_ids = array_diff($r_ids, $k_ids);

		foreach ($items as $item) {
			if (in_array($item->db_id, $r_ids))
				$item->classes = array_diff($item->classes, array('current-menu-ancestor', 'current-menu-parent', 'current_page_parent', 'current_page_ancestor'));
		}

		return $items;
	}

	/*
	 * adds hreflang attribute for the language switcher menu items
	 * available since WP3.6
	 *
	 * @since 1.1
	 *
	 * @param array $atts
	 * @return array modified $atts
	 */
	public function nav_menu_link_attributes($atts, $item, $args) {
		if (isset($item->lang))
			$atts['hreflang'] = $item->lang;
		return $atts;
	}

	/*
	 * get the menu in the correct language
	 * avoid infinite loop and http://core.trac.wordpress.org/ticket/9968
	 *
	 * @since 1.1
	 *
	 * @param object $term nav menu
	 * @return object
	 */
	public function get_nav_menu($term) {
		static $once = false;
		if (!$once && $tr = pll_get_term($term->term_id)) {
			$once = true; // breaks the loop
			$term = get_term($tr, 'nav_menu');
			$once = false; // for the next call
		}

		return $term;
	}
}
