<?php

if ( ! defined('WPMUDEV_METABOX_DIR') ) {
	define('WPMUDEV_METABOX_DIR', plugin_dir_path(__FILE__));
}

if ( ! defined('WPMUDEV_METABOX_URL') ) {
	define('WPMUDEV_METABOX_URL', plugin_dir_url(__FILE__));
}

if ( ! defined('WPMUDEV_METABOX_VERSION') ) {
	define('WPMUDEV_METABOX_VERSION', '1.0');
}


require_once WPMUDEV_Metabox::class_dir('api.php');

/**
 * Stores the field scripts that have been printed (so we don't print them more than once)
 *
 * @global array
 * @name $printed_field_scripts
 */
$GLOBALS['wpmudev_metaboxes_printed_field_scripts'] = array();

// Save the state of a given metabox
add_action('wp_ajax_wpmudev_metabox_save_state', array('WPMUDEV_Metabox', 'ajax_save_state'));

class WPMUDEV_Metabox {
	/**
	 * Refers to if the metabox fields are saved
	 *
	 * @since 1.0
	 * @access public
	 * @var bool
	 */
	var $fields_saved = false;
	
	/**
	 * Refers to the meta box's constructor arguments
	 *
	 * @since 1.0
	 * @access public
	 * @var array
	 */
	var $args = array();
	
	/**
	 * Refers to the meta box's form fields
	 *
	 * @since 1.0
	 * @access public
	 * @var array
	 */
	var $fields = array();
	
	/**
	 * Refers to the nonce action
	 *
	 * @since 1.0
	 * @access public
	 * @var string
	 */
	var $nonce_action = '';

	/**
	 * Refers to the nonce field name
	 *
	 * @since 1.0
	 * @access public
	 * @var string
	 */
	var $nonce_name = '';
	
	/**
	 * Refers to the metabox's current status (e.g. active or not)
	 *
	 * @since 1.0
	 * @access public
	 * @var bool
	 */
	var $is_active = null;
	
	/**
	 * If the current metabox is a settings page metabox
	 *
	 * @since 1.0
	 * @access public
	 * @var bool
	 */
	var $is_settings_metabox = null;
	
	/**
	 * Refers to any custom field validation messages
	 *
	 * @since 1.0
	 * @access public
	 * @var array
	 */
	var $validation_messages = array();
	
	/**
	 * Refers to the base directory
	 *
	 * @since 1.0
	 * @access public
	 * @var string
	 */
	public static $dir = '';
	
	/**
	 * Refers to the base url
	 *
	 * @since 1.0
	 * @access public
	 * @var string
	 */
	public static $url = '';
	
	/**
	 * Refers to the metaboxes that are registered to the current screen
	 *
	 * @since 1.0
	 * @access public
	 * @var array
	 */
	public static $metaboxes = array();
	
	/**
	 * The number of metaboxes that have been displayed
	 *
	 * @since 1.0
	 * @access public
	 * @var int
	 */
	public static $did_metabox_count = 0;
	
	/**
	 * Save the state of the metabox (open or closed)
	 *
	 * @since 1.0
	 * @access public
	 * @action wp_ajax_wpmudev_metabox_save_state
	 */
	public static function ajax_save_state() {
		$option = 'wpmudev_metabox_states';
		$data = get_option($option, array());
		$id = isset($_POST['id']) ? $_POST['id'] : null;
		$is_closed = isset($_POST['closed']) ? $_POST['closed'] : null;
		
		if ( is_null($id) || is_null($is_closed) ) {
			return;
		}
		
		if ( $is_closed == 'true' ) {
			$is_closed = true;
		} else {
			$is_closed = false;
		}
		
		self::push_to_array($data, $id, $is_closed);
		
		// is_network_admin() doesn't work for ajax calls - see https://core.trac.wordpress.org/ticket/22589
		if ( is_multisite() && preg_match('#^' . network_admin_url() . '#i', $_SERVER['HTTP_REFERER']) ) {
			update_site_option($option, $data);
		} else {
			update_option($option, $data);
		}
		
		die;
	}
	
	/**
	 * Constructor function
	 *
	 * @since 1.0
	 * @access public
	 * @param array $args {
	 *		An array of arguments. Optional.
	 *
	 *		@type string $class The class of the metabox.
	 *		@type string $id The id of the metabox (required).
	 *		@type string $title The title of the metabox (required).
	 *		@type string $desc The description of the metabox.
	 *		@type string $post_type The post type to display the metabox on.
	 *		@type array $screen_ids The screen ID(s) to display the metabox on.
	 *		@type string $context The context of the metabox (advanced, normal, side).
	 *		@type string $priority The priority of the metabox (default, high, normal).
	 *		@type string $option_name If not a post metabox, enter the option name that will be used to retrieve/save the field's value (e.g. plugin_settings).
	 *		@type string $site_option_name If not a post metabox, enter the site option name that will be used to retrieve/save the field's value (e.g. plugin_settings).
	 *		@type int $order Display order for settings metaboxes. If this is not entered metaboxes will be rendered in the order they logically show up in the code. Defaults to 10.
	 *		@type array $conditional {
	 *			Conditionally hide/show this field if another field value is a certain value.
	 *
	 *			Example 1: array('name' => 'field_name', 'value' => 'field_value', 'action' => 'show');
	 *			Example 2: array('operator' => 'AND', array('name' => 'field_name', 'value' => 'field_value', 'action' => 'show'), array('name' => 'field_name', 'value' => 'field_value', 'action' => 'show'));
	 *			Example 3: array('operator' => 'OR', array('name' => 'field_name', 'value' => 'field_value', 'action' => 'show'), array('name' => 'field_name', 'value' => 'field_value', 'action' => 'show'));
	 *
	 *			@type string $name The name of the field to do the comparison on.
	 *			@type string $value The value to check against. Use "-1" to check for a checkbox being unchecked.
	 *			@type string $action The action to perform (show/hide).
	 *		}
	 * }
	 */
	public function __construct( $args = array() ) {
		$this->args = array_replace_recursive(array(
			'class' => 'postbox wpmudev-postbox',
			'id' => '',
			'title' => '',
			'desc' => null,
			'post_type' => '',
			'screen_ids' => array(),
			'context' => 'advanced',
			'priority' => 'default',
			'option_name' => '',
			'site_option_name' => '',
			'order' => 10,
			'conditional' => array(),
		), $args);
		
		$this->nonce_action = 'wpmudev_metabox_' . str_replace('-', '_', $this->args['id']) . '_save_fields';
		$this->nonce_name = $this->nonce_action . '_nonce';
	
		$this->localize();
		$this->load_fields();
		$this->init_conditional_logic();
		$this->add_html_classes();
				
		add_action('admin_enqueue_scripts', array(&$this, 'admin_enqueue_styles'));		
		add_action('admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'));
		add_action('add_meta_boxes_' . $this->args['post_type'], array(&$this, 'add_meta_boxes'), $this->args['order']);
		add_action('wpmudev_metabox/render_settings_metaboxes', array(&$this, 'maybe_render'), $this->args['order']);
		add_action('save_post', array(&$this, 'maybe_save_fields'));
		add_filter('postbox_classes_' . $this->args['post_type'] . '_' . $this->args['id'], array(&$this, 'add_meta_box_classes'));
		add_action('admin_notices', array(&$this, 'admin_notices'));
		add_action('network_admin_notices', array(&$this, 'admin_notices'));
		add_action('init', array(&$this, 'maybe_save_settings_fields'), 99);
	}
	
	/**
	 * Add HTML classes to metabox
	 *
	 * @since 1.0
	 * @access public
	 */
	public function add_html_classes() {
		if ( $this->is_closed() && $this->is_active() ) {
			$this->args['class'] .= ' closed';
		}
	}
	
	/**
	 * Determine if metabox is closed
	 *
	 * @since 1.0
	 * @access public
	 */
	public function is_closed() {
		$settings = ( is_network_admin() ) ? get_site_option('wpmudev_metabox_states', array()) : get_option('wpmudev_metabox_states', array());
		return (( isset($settings[$this->args['id']]) ) ? (bool) $settings[$this->args['id']] : false);
	}

	/**
	 * Localize the library
	 *
	 * The function will try to load the translation file from the WP_LANG_DIR
	 *
	 * @since 1.0
	 * @access public
	 */
	public function localize() {
		$domain = 'wpmudev_metaboxes';
		// The plugin_locale filter is defined in /wp-includes/l10n.php
		$locale = apply_filters('plugin_locale', get_locale(), $domain);
		$lang_file = WP_LANG_DIR . '/' . $domain . '-' . $locale . '.mo';
		
		load_textdomain($domain, $lang_file);
	}

	/**
	 * Initializes conditional logic attributes
	 *
	 * @since 1.0
	 * @access public
	 */
	public function init_conditional_logic() {
		if ( empty($this->args['conditional']) ) {
			return;
		}
		
		$this->args['custom']['data-conditional-operator'] = 'OR';
		
		if ( isset($this->args['conditional']['operator']) ) {
			$this->args['custom']['data-conditional-operator'] = $this->args['conditional']['operator'];
			unset($this->args['conditional']['operator']);
		}
		
		if ( isset($this->args['conditional']['action']) ) {
			$this->args['custom']['data-conditional-action'] = $this->args['conditional']['action'];
			unset($this->args['conditional']['action']);
		}
		
		if ( array_key_exists('name', $this->args['conditional']) || array_key_exists('value', $this->args['conditional']) ) {
			$conditional = array(
				'name' => $this->args['conditional']['name'],
				'value' => $this->args['conditional']['value'],
			);
			$this->args['conditional'] = array($conditional);
		}
		
		foreach ( $this->args['conditional'] as $index => $value ) {
			$this->args['custom']['data-conditional-name-' . $index] = $value['name'];
			$this->args['custom']['data-conditional-value-' . $index] = ( is_array($value['value']) && count($value['value']) > 1 ) ? implode('||', $value['value']) : $value['value'];
		}
		
		$this->args['class'] .= ' wpmudev-metabox-has-conditional';
	}
	
	/**
	 * Displays the "settings updated" notice
	 *
	 * @since 1.0
	 * @access public
	 */
	public function admin_notices() {
		if ( isset($_GET['wpmudev_metabox_settings_saved']) && ! wp_cache_get('settings_saved', 'wpmudev_metaboxes') ) {
			echo '<div class="updated"><p>' . __('Settings Saved', 'wpmudev_metaboxes') . '</p></div>';
			wp_cache_set('settings_saved', true, 'wpmudev_metaboxes'); // Only show the message once per screen
		}
		
		if ( isset($_GET['wpmudev_metabox_settings_failed']) && ! wp_cache_get('settings_failed', 'wpmudev_metaboxes') ) {
			echo '<div class="error"><p>' . __('Due to prolonged inactivity on this page, one or more settings were not saved. Please try again.', 'wpmudev_metaboxes') . '</p></div>';
			wp_cache_set('settings_failed', true, 'wpmudev_metaboxes'); // Only show the message once per screen
		}
	}

	/**
	 * Determine if fields should be saved
	 *
	 * @since 1.0
	 * @access public
	 * @action save_post
	 */	
	public function maybe_save_fields( $post_id ) {
		if ( ! $this->is_active() ) {
			return;
		}
		
		$this->save_fields($post_id);
	}
	
	/**
	 * Determine if settings fields should be saved
	 *
	 * @since 1.0
	 * @access public
	 * @action init
	 */
	public function maybe_save_settings_fields() {
		if ( ! $this->is_active() || ! $this->is_settings_metabox() ) {
			return;
		}
		
		$opt_name = ( ! empty($this->args['site_option_name']) ) ? $this->args['site_option_name'] : $this->args['option_name'];
		$this->save_fields($opt_name);
	}
	
	/**
	 * Determines if a metabox should be rendered
	 *
	 * @since 1.0
	 * @access public
	 */
	public function maybe_render() {
		if ( ! $this->is_active() ) {
			return false;
		}
  	
  	$opt_name = ( ! empty($this->args['site_option_name']) ) ? $this->args['site_option_name'] : $this->args['option_name'];
		$this->render($opt_name);
	}
	
	/**
	 * Adds classes to the metabox for easier styling
	 *
	 * @since 1.0
	 * @access public
	 * @filter postbox_classes_{$post_type}_{$id}
	 * @param array $classes
	 * @return array
	 */
	public function add_meta_box_classes( $classes ) {
		return array_merge($classes, array('wpmudev-postbox'));
	}

	/**
	 * Enqueues admin scripts
	 *
	 * @since 1.0
	 * @access public
	 */	
	public function admin_enqueue_scripts() {
		if ( ! $this->is_active() ) {
			return false;
		}
		
		wp_enqueue_script('jquery-validate', $this->class_url('ui/js/jquery.validate.min.js'), array('jquery'), '1.12');
		wp_enqueue_script('wpmudev-metaboxes-admin', $this->class_url('ui/js/admin.js'), array('jquery', 'jquery-validate', 'jquery-effects-highlight'), $this->version, true);
		
		$default_messages = array(
			'alphanumeric_error_msg' => __('Please enter only letters and numbers', 'wpmudev_metaboxes'),
		);
		
		wp_localize_script('wpmudev-metaboxes-admin', 'WPMUDEV_Metaboxes_Validation_Messages', array_merge($default_messages, $this->validation_messages));
		wp_localize_script('wpmudev-metaboxes-admin', 'WPMUDEV_Metaboxes', array(
			'spinner_url' => admin_url('images/spinner.gif'),
		));
	}
	
	/**
	 * Enqueues admin scripts
	 *
	 * @since 1.0
	 * @access public
	 */	
	public function admin_enqueue_styles() {
		if ( ! $this->is_active() ) {
			return false;
		}
		
		wp_enqueue_style('wpmudev-metaboxes-admin', $this->class_url('ui/css/admin.css'), array(), $this->version);
	}
	
	/**
	 * Gets the base class directory with optional path
	 *
	 * @since 1.0
	 * @access public
	 * @param string $path
	 * @return string
	 */
	public static function class_dir( $path = '' ) {
		return WPMUDEV_METABOX_DIR . ltrim($path, '/');
	}

	/**
	 * Gets the base class url with optional path
	 *
	 * @since 1.0
	 * @access public
	 * @param string $path
	 * @return string
	 */
	public static function class_url( $path = '' ) {
		return WPMUDEV_METABOX_URL . ltrim($path, '/');
	}

	/**
	 * Get all files from a given directory
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param string $dir The full path of the directory
	 * @param string $ext Get only files with a given extension. Set to NULL to get all files.
	 * @return array or false if no files exist
	 */
	public static function get_dir_files( $dir, $ext = 'php' ) {
		$myfiles = array();
		
		if ( ! is_null($ext) )
			$ext = '.' . $ext;
		
		if ( false === file_exists($dir) )
			return false;
		
		$dir = trailingslashit($dir);
		$files = glob($dir . '*' . $ext);
		
		return ( empty($files) ) ? false : $files;
	}

	/**
	 * Includes all files in a given directory
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param string $dir The directory to work with
	 * @param string $ext Only include files with this extension
	 */
	public static function include_dir( $dir, $ext = 'php' ) {
		if ( false === ($files = self::get_dir_files($dir, $ext)) )
			return false;
		
		foreach ( $files as $file ) {
			include_once $file;
		}
	}
		
	/**
	 * Load fields
	 *
	 * @since 1.0
	 * @access public
	 */
	public function load_fields() {
		require_once self::class_dir('class-wpmudev-field.php');
		self::include_dir(self::class_dir('fields'));	
	}	
		
	/**
	 * Adds the meta box to the appropriate screen
	 *
	 * @since 1.0
	 * @access public
	 * @param object $post
	 */
	public function add_meta_boxes( $post ) {
		add_meta_box($this->args['id'], $this->args['title'], array(&$this, 'render'), $this->args['post_type'], $this->args['context'], $this->args['priority']);
	}
	
	/**
	 * Renders the meta box
	 *
	 * @since 1.0
	 * @access public
	 * @param object/string post
	 */
	public function render( $post = null ) {
		if ( $post instanceof WP_Post ) {
			$post = $post->ID;
		}
		
		if ( $this->is_settings_metabox() ) :
			$atts = '';
			foreach ( (array) $this->args['custom'] as $name => $val ) {
				$atts .= ' ' . $name . '="' . esc_attr($val) . '"';	
			}
			
			if ( self::$did_metabox_count == 0 ) : ?>
			<div id="poststuff">
				<div class="meta-box-sortables">
		<?php
			endif; ?>
					<div id="<?php echo $this->args['id']; ?>" class="<?php echo $this->args['class']; ?>"<?php echo $atts; ?>>
						<div class="handlediv" title="Click to toggle"><br /><br /></div>
						<h3 class="hndle"><span><?php echo $this->args['title']; ?></span></h3>
						<div class="inside">
		<?php
		endif;
		
		wp_nonce_field($this->nonce_action, $this->nonce_name);
		
		if ( ! is_null($this->args['desc']) ) : ?>
							<div class="wpmudev-metabox-desc"><?php echo $this->args['desc']; ?></div>
		<?php
		endif; ?>
							<div class="wpmudev-fields clearfix">
		<?php
		foreach ( $this->fields as $field ) : ?>
								<div class="wpmudev-field <?php echo str_replace('_', '-', strtolower(get_class($field))); ?>">
									<div class="wpmudev-field-label"><?php echo $field->args['label']['text'] . (( strpos($field->args['class'], 'required') !== false ) ? '<span class="required">*</span>' : ''); ?></div>
									<div class="wpmudev-field-desc"><?php echo $field->args['desc']; ?></div>
									<?php $field->display($post); ?>
								</div>
		<?php
		endforeach; ?>
							</div>
		<?php
		if ( $this->is_settings_metabox() ) : ?>
					</div>
				</div>
				<p class="submit">
					<input class="button-primary" type="submit" name="submit_settings" value="<?php _e('Save Changes', 'mp') ?>" />
				</p>
		<?php
			if ( self::$did_metabox_count == (count(self::$metaboxes) - 1) ) : ?>
			</div>
		</div>
		<?php
			endif;
		endif;
		
		self::$did_metabox_count += 1;
	}
	
	/**
	 * Pushes a value with a given array with given key
	 *
	 * @since 1.0
	 * @access public
	 * @param array $array The array to work with.
	 * @param string $key_string
	 * @param mixed $value
	 */
	public function push_to_array( &$array, $key_string, $value ) {
    $keys = explode('->', $key_string);
    $branch = &$array;
    
    while ( count($keys) ) {
    	$key = array_shift($keys);
    	
	    if ( ! is_array($branch) ) {
		  	$branch = array();
	    }	
	        
	    $branch = &$branch[$key];
    }
    
    $branch = $value;
	}
	
	/**
	 * Saves the metabox fields to the database
	 *
	 * @since 1.0
	 * @access public
	 * @param int $post_id The current post ID
	 */
	public function save_fields( $post_id ) {
		if ( ! isset($_POST[$this->nonce_name]) || (isset($_POST[$this->nonce_name]) && ! wp_verify_nonce($_POST[$this->nonce_name], $this->nonce_action)) ) {
			// Bail - nonce is not set or could not be verified
			return;
		}
		
		// Avoid infinite loops later (e.g. when calling wp_insert_post, etc)
		remove_action('save_post', array(&$this, 'save_fields'));

		/**
		 * Runs right before save_fields is run, but after nonces have been verified.
		 *
		 * @since 3.0
		 * @param WPMUDEV_Metabox $this The current metabox
		 */
		do_action('wpmudev_metabox/before_save_fields', $this);
		do_action('wpmudev_metabox/before_save_fields/' . $this->args['id'], $this);
				
		// For settings metaboxes we don't want to call the internal save methods for each field
		if ( ! is_numeric($post_id) ) {
			$settings = ( ! empty($this->args['site_option_name']) ) ? get_site_option($post_id, array()) : get_option($post_id, array());
			
			foreach ( $this->fields as $field ) {
				$post_key = $field->get_post_key($field->args['name']);
				$value = $field->get_post_value($post_key);

				if ( $field instanceof WPMUDEV_Field_Repeater ) {
					$values = $field->sort_subfields($value);
					
					if ( count($values) == 2 ) {
						$values = array_merge($values['existing'], $values['new']);
					}
					
					foreach ( $values as $idx => $array ) {
						$index = 0;                                                                                                                                                                                                                     
						foreach ( $array as $idx2 => $val ) {
							$values[$idx][$index] = $field->subfields[$index]->sanitize_for_db($val);
							$index ++;
						}
					}
					
					$value = $values;
				} else {
					$value = $field->sanitize_for_db($value);
				}
				
				$settings = $this->push_to_array($settings, $post_key, $value);
			}
			
			if ( ! empty($this->args['site_option_name']) ) {
				update_site_option($post_id, $settings);
			} else {
				update_option($post_id, $settings);
			}
			
			/**
			 * Fires after the settings metabox has been saved.
			 *
			 * @since 3.0
			 * @param WPMUDEV_Metabox $this The metabox that was saved.
			 */
			do_action('wpmudev_metabox/settings_metabox_saved', $this);
			
			if ( did_action('wpmudev_metabox/settings_metabox_saved') == count(self::$metaboxes) ) {
				// Redirect to avoid accidental saves on page refresh
				wp_redirect(add_query_arg('wpmudev_metabox_settings_saved', 1), 301);
				exit;
			}
		} else {	
			// Make sure $post_id isn't a revision
			if ( wp_is_post_revision($post_id) ) {
				return;
			}

			// Make sure we only run once
			if ( wp_cache_get('save_fields', 'wpmudev_metaboxes') ) {
				return;
			}
			wp_cache_set('save_fields', true, 'wpmudev_metaboxes');
		
			/**
			 * Fires after the appropriate nonce's have been verified for fields to tie into.
			 *
			 * @since 3.0
			 * @param int $post_id Post ID.
			 */
			do_action('wpmudev_metabox/save_fields', $post_id);
		}
	}
	
	/**
	 * Checks if the metabox is a settings metabox
	 *
	 * @since 1.0
	 * @access public
	 * @return bool
	 */
	public function is_settings_metabox() {
		if ( ! is_null($this->is_settings_metabox) ) {
			return $this->is_settings_metabox;
		}

		$current_screen = $this->get_current_screen();
		
		$this->is_settings_metabox = false;
		if ( in_array($current_screen->id, $this->args['screen_ids']) ) {
			$this->is_settings_metabox = true;
		}
		
		return $this->is_settings_metabox;
	}
	
	/**
	 * Safely gets the $current_screen object even before the current_screen hook is fired
	 *
	 * @since 1.0
	 * @access public
	 * @uses $current_screen, $hook_suffix, $pagenow, $taxnow, $typenow
	 * @return object
	 */
	public function get_current_screen() {
		global $current_screen, $hook_suffix, $pagenow, $taxnow, $typenow;
		
		if ( empty($current_screen) ) {
			//set current screen (not normally available here) - this code is derived from wp-admin/admin.php
			require_once ABSPATH . 'wp-admin/includes/screen.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			
			if ( isset($_GET['page']) ) {
				$plugin_page = wp_unslash($_GET['page']);
				$plugin_page = plugin_basename($plugin_page);
			}
			
			if ( isset($_REQUEST['post_type']) && post_type_exists($_REQUEST['post_type']) ) {
				$typenow = $_REQUEST['post_type'];
			} else {
				$typenow = '';
			}
			
			if ( isset($_REQUEST['taxonomy']) && taxonomy_exists($_REQUEST['taxonomy']) ) {
				$taxnow = $_REQUEST['taxonomy'];
			} else {
				$taxnow = '';
			}

			if ( isset($plugin_page) ) {
				if ( ! empty($typenow) ) {
					$the_parent = $pagenow . '?post_type=' . $typenow;
				} else {
					$the_parent = $pagenow;
				}
					
				if ( ! $page_hook = get_plugin_page_hook($plugin_page, $the_parent) ) {
					$page_hook = get_plugin_page_hook($plugin_page, $plugin_page);
					// backwards compatibility for plugins using add_management_page
					if ( empty( $page_hook ) && 'edit.php' == $pagenow && '' != get_plugin_page_hook($plugin_page, 'tools.php') ) {
						// There could be plugin specific params on the URL, so we need the whole query string
						if ( ! empty($_SERVER[ 'QUERY_STRING' ]) ) {
							$query_string = $_SERVER[ 'QUERY_STRING' ];
						} else {
							$query_string = 'page=' . $plugin_page;
						}
							
						wp_redirect( admin_url('tools.php?' . $query_string) );
						exit;
					}
				}
				
				unset($the_parent);
			}
			
			$hook_suffix = '';
			if ( isset($page_hook) ) {
				$hook_suffix = $page_hook;
			} else if ( isset($plugin_page) ) {
				$hook_suffix = $plugin_page;
			} else if ( isset($pagenow) ) {
				$hook_suffix = $pagenow;
			}
			
			set_current_screen();
		}
		
		return get_current_screen();
	}
	
	/**
	 * Checks if the current metabox is active for the current page
	 *
	 * @since 1.0
	 * @access public
	 * @return bool
	 */
	public function is_active() {
		if ( ! is_null($this->is_active) ) {
			return $this->is_active;
		}
		
		//only load metaboxes on appropriate post page and for assigned post type or screen_id
		$this->is_active = true;
		if ( $this->args['post_type'] != $this->get_current_screen()->id && ! in_array($this->get_current_screen()->id, $this->args['screen_ids']) ) {
			$this->is_active = false;
		}
		
		if ( in_array($this->get_current_screen()->id, $this->args['screen_ids']) ) {
			$this->is_settings_metabox = true;
		}
		
		if ( $this->is_active ) {
			// Set this as an active metabox
			self::$metaboxes[$this->args['id']] = '';
		}
		
		return $this->is_active;	
	}
	
	/**
	 * Adds a form field to the meta box
	 *
	 * @since 1.0
	 * @access public
	 * @param string $type
	 * @param array $args
	 * @return WPMUDEV_Field
	 */
	public function add_field( $type, $args = array() ) {		
		$class = 'WPMUDEV_Field_' . ucfirst($type);
				
		if ( ! class_exists($class) || ! $this->is_active() ) {
			return false;	
		}
		
		if ( ! empty($args['custom_validation_message']) ) {
			$this->validation_messages = array_merge($this->validation_messages, array($args['name'] => $args['custom_validation_message']));
		}
		
		$args['echo'] = false;
		$field = new $class($args);
		$field->metabox = $this;
		$this->fields[] = $field;
		
		return $field;
	}
}