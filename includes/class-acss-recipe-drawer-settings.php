<?php
/**
 * Settings screen for custom recipes.
 *
 * @package ACSS_Recipe_Drawer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACSS_Recipe_Drawer_Settings {

	const PAGE_SLUG  = 'acss-recipe-drawer';
	const ACTION_SAVE = 'acss_recipe_drawer_save';

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	/**
	 * Register the Settings sub-menu page.
	 */
	public function add_page() {
		add_options_page(
			__( 'ACSS Recipe Drawer', 'acss-recipe-drawer' ),
			__( 'ACSS Recipe Drawer', 'acss-recipe-drawer' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$custom   = $this->get_raw_custom();
		$acss_active = class_exists( '\Automatic_CSS\API' );
		$builtin_count = $acss_active ? ACSS_Recipe_Drawer::get_instance()->recipes()->count_builtins() : 0;
		?>
<div class="wrap">
	<h1><?php echo esc_html__( 'ACSS Recipe Drawer', 'acss-recipe-drawer' ); ?></h1>

	<?php if ( ! $acss_active ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'Automatic.css (ACSS) does not appear to be active. Built-in recipes will be unavailable until ACSS is installed and activated.', 'acss-recipe-drawer' ); ?>
		</p></div>
	<?php else : ?>
		<div class="notice notice-info"><p>
			<?php
			printf(
				/* translators: %d: number of built-in recipes detected. */
				esc_html__( 'ACSS is active. %d built-in recipes detected.', 'acss-recipe-drawer' ),
				(int) $builtin_count
			);
			?>
		</p></div>
	<?php endif; ?>

	<p><?php esc_html_e( 'Custom recipes are merged over the built-ins. A custom recipe with the same name as a built-in overrides it and is labelled "custom" in the drawer.', 'acss-recipe-drawer' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
		<?php wp_nonce_field( self::ACTION_SAVE ); ?>

		<table class="widefat striped" id="acss-recipe-drawer-table">
			<thead>
				<tr>
					<th style="width:18%;"><?php esc_html_e( 'Name', 'acss-recipe-drawer' ); ?></th>
					<th><?php esc_html_e( 'CSS', 'acss-recipe-drawer' ); ?></th>
					<th style="width:22%;"><?php esc_html_e( 'Description', 'acss-recipe-drawer' ); ?></th>
					<th style="width:6%;"></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$index = 0;
			if ( ! empty( $custom ) ) :
				foreach ( $custom as $row ) :
					$name = isset( $row['name'] ) ? $row['name'] : '';
					$css  = isset( $row['css'] ) ? $row['css'] : '';
					$desc = isset( $row['description'] ) ? $row['description'] : '';
					$this->render_row( $index, $name, $css, $desc );
					++$index;
				endforeach;
			endif;
			$this->render_row( $index, '', '', '' );
			?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button" id="acss-recipe-drawer-add-row">
				<?php esc_html_e( 'Add recipe', 'acss-recipe-drawer' ); ?>
			</button>
			<?php submit_button( __( 'Save recipes', 'acss-recipe-drawer' ), 'primary', 'acss_recipe_drawer_submit', false ); ?>
		</p>
	</form>

	<script>
	(function () {
		var idx = <?php echo (int) ( $index + 1 ); ?>;
		document.getElementById('acss-recipe-drawer-add-row').addEventListener('click', function () {
			var tbody = document.querySelector('#acss-recipe-drawer-table tbody');
			var tr = document.createElement('tr');
			tr.innerHTML =
				'<td><input type="text" name="recipes[' + idx + '][name]" value="" class="regular-text" placeholder="my-recipe" /></td>' +
				'<td><textarea name="recipes[' + idx + '][css]" rows="4" class="large-text code"></textarea></td>' +
				'<td><input type="text" name="recipes[' + idx + '][description]" value="" class="regular-text" /></td>' +
				'<td><button type="button" class="button acss-recipe-drawer-remove"><?php echo esc_js( __( 'Remove', 'acss-recipe-drawer' ) ); ?></button></td>';
			tbody.appendChild(tr);
			idx++;
		});
		document.addEventListener('click', function (e) {
			if (e.target && e.target.classList && e.target.classList.contains('acss-recipe-drawer-remove')) {
				e.target.closest('tr').remove();
			}
		});
	})();
	</script>
</div>
		<?php
	}

	/**
	 * Render a single recipe row.
	 *
	 * @param int    $index
	 * @param string $name
	 * @param string $css
	 * @param string $desc
	 */
	private function render_row( $index, $name, $css, $desc ) {
		?>
		<tr>
			<td><input type="text" name="recipes[<?php echo (int) $index; ?>][name]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" placeholder="my-recipe" /></td>
			<td><textarea name="recipes[<?php echo (int) $index; ?>][css]" rows="4" class="large-text code"><?php echo esc_textarea( $css ); ?></textarea></td>
			<td><input type="text" name="recipes[<?php echo (int) $index; ?>][description]" value="<?php echo esc_attr( $desc ); ?>" class="regular-text" /></td>
			<td><button type="button" class="button acss-recipe-drawer-remove"><?php esc_html_e( 'Remove', 'acss-recipe-drawer' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Handle the admin-post save action.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['action'] ) || self::ACTION_SAVE !== $_POST['action'] ) {
			return;
		}
		check_admin_referer( self::ACTION_SAVE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'acss-recipe-drawer' ) );
		}

		$recipes = isset( $_POST['recipes'] ) ? wp_unslash( $_POST['recipes'] ) : array();
		$saved   = $this->sanitize_recipes( $recipes );
		update_option( ACSS_Recipe_Drawer_Recipes::OPTION_NAME, $saved );

		// Invalidate the recipe cache so the drawer sees new custom recipes.
		ACSS_Recipe_Drawer::get_instance()->recipes()->flush_cache();

		$referrer = wp_get_referer();
		$redirect = $referrer ? add_query_arg( 'acss_rd_saved', '1', $referrer ) : admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Sanitize the posted recipe rows.
	 *
	 * Name: lowercase, [a-z0-9-] only; reject empty and duplicates.
	 * CSS: stored raw (wp_unslash already applied). No wp_kses / sanitize_textarea_field,
	 * which would mangle `>` and quotes. Safety comes from output encoding.
	 *
	 * @param array $rows
	 * @return array<string, array{css: string, description: string}>
	 */
	private function sanitize_recipes( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$raw_name = isset( $row['name'] ) ? (string) $row['name'] : '';
			$name = strtolower( preg_replace( '/[^a-z0-9-]/i', '-', trim( $raw_name ) ) );
			$name = trim( $name, '-' );
			if ( '' === $name ) {
				continue;
			}
			$css = isset( $row['css'] ) ? (string) $row['css'] : '';
			$desc = isset( $row['description'] ) ? (string) $row['description'] : '';
			// Custom overrides on duplicate name: last one wins.
			$clean[ $name ] = array(
				'css'         => $css,
				'description' => $desc,
			);
		}
		return $clean;
	}

	/**
	 * Get custom recipes in their raw stored form (name keyed).
	 *
	 * @return array<int, array{name: string, css: string, description: string}>
	 */
	private function get_raw_custom() {
		$option = get_option( ACSS_Recipe_Drawer_Recipes::OPTION_NAME, array() );
		if ( ! is_array( $option ) ) {
			return array();
		}
		$rows = array();
		foreach ( $option as $name => $data ) {
			$rows[] = array(
				'name'        => (string) $name,
				'css'         => isset( $data['css'] ) ? (string) $data['css'] : '',
				'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
			);
		}
		return $rows;
	}

	/**
	 * Show the save-success notice.
	 */
	public function maybe_show_notices() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}
		if ( isset( $_GET['acss_rd_saved'] ) && '1' === $_GET['acss_rd_saved'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Custom recipes saved.', 'acss-recipe-drawer' ) . '</p></div>';
		}
	}
}
