<?php
/**
 * Main ACSS Recipe Drawer class: context detection, enqueue, AJAX.
 *
 * @package ACSS_Recipe_Drawer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACSS_Recipe_Drawer {

	const NONCE_ACTION = 'acss_recipe_drawer';
	const AJAX_ACTION  = 'acss_recipe_drawer_get';
	const SCRIPT_HANDLE = 'acss-recipe-drawer';

	/**
	 * Singleton instance.
	 *
	 * @var ACSS_Recipe_Drawer|null
	 */
	private static $instance = null;

	/**
	 * Recipe provider.
	 *
	 * @var ACSS_Recipe_Drawer_Recipes
	 */
	private $recipes;

	/**
	 * Get the singleton instance.
	 *
	 * @return ACSS_Recipe_Drawer
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->recipes = new ACSS_Recipe_Drawer_Recipes();
	}

	/**
	 * Initialize hooks.
	 */
	private function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_get_recipes' ) );

		$settings = new ACSS_Recipe_Drawer_Settings();
		$settings->init();
	}

	// -------------------------------------------------------------------------
	// Context detection.
	// -------------------------------------------------------------------------

	/**
	 * Whether the current request is the Builderius canvas iframe.
	 *
	 * @return bool
	 */
	private function is_canvas() {
		return isset( $_GET['builderius_inner_preview'] );
	}

	/**
	 * Whether the ACSS API class is available.
	 *
	 * @return bool
	 */
	private function is_acss_active() {
		return class_exists( '\Automatic_CSS\API' );
	}

	/**
	 * Whether the current user may use the drawer.
	 *
	 * @return bool
	 */
	private function can_use() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( 'builderius-development' ) || current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Enqueue.
	// -------------------------------------------------------------------------

	/**
	 * Enqueue the drawer script in the Builderius canvas.
	 */
	public function enqueue() {
		if ( ! $this->is_canvas() || ! $this->is_acss_active() || ! $this->can_use() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ACSS_RECIPE_DRAWER_URL . 'assets/drawer.js',
			array(),
			ACSS_RECIPE_DRAWER_VERSION,
			true
		);

		$css_path = ACSS_RECIPE_DRAWER_DIR . 'assets/drawer.css';
		$css_text = file_exists( $css_path ) ? file_get_contents( $css_path ) : '';
		if ( false === $css_text ) {
			$css_text = '';
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'acssRecipeDrawer',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'cssText' => $css_text,
				'strings' => array(
					'tab'        => __( 'ACSS Recipes', 'acss-recipe-drawer' ),
					'placeholder' => __( 'Type a recipe name...', 'acss-recipe-drawer' ),
					'copy'       => __( 'Copy', 'acss-recipe-drawer' ),
					'clear'      => __( 'Clear', 'acss-recipe-drawer' ),
					'copied'     => __( 'Copied!', 'acss-recipe-drawer' ),
					'refresh'    => __( 'Refresh', 'acss-recipe-drawer' ),
					'custom'     => __( 'custom', 'acss-recipe-drawer' ),
					'loading'    => __( 'Loading...', 'acss-recipe-drawer' ),
					'error'      => __( 'Failed to load recipes.', 'acss-recipe-drawer' ),
					'empty'      => __( 'No recipes found.', 'acss-recipe-drawer' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// AJAX.
	// -------------------------------------------------------------------------

	/**
	 * Serve the merged recipe map to the drawer.
	 *
	 * Registered only on wp_ajax_ (logged-in), never wp_ajax_nopriv_.
	 */
	public function ajax_get_recipes() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! $this->can_use() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$refresh = isset( $_POST['refresh'] ) && '1' === (string) $_POST['refresh'];
		$recipes = $this->recipes->get_all( $refresh );

		wp_send_json_success( array( 'recipes' => $recipes ) );
	}

	/**
	 * Expose the recipe provider (used by the settings screen).
	 *
	 * @return ACSS_Recipe_Drawer_Recipes
	 */
	public function recipes() {
		return $this->recipes;
	}
}
