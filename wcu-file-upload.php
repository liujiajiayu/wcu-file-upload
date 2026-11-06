<?php
/**
 * Plugin Name: WCU File Upload
 * Description: Secure customer file uploads for WooCommerce custom products, with cart, order and email integration.
 * Version: 1.2.1
 * Author: LumiBoard
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.8
 * Text Domain: wcu-file-upload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WCU_File_Upload {
	const VERSION        = '1.2.1';
	const OPTION_KEY     = 'wcu_file_upload_settings';
	const PRODUCT_META   = '_wcu_upload_mode';
	const UPLOAD_POST    = 'wcu_upload';
	const ITEM_META_IDS  = '_wcu_upload_ids';
	const CRON_HOOK      = 'wcu_cleanup_uploads';
	const NONCE_ACTION   = 'wcu_upload_file';

	private static $instance = null;
	private $rendered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'init', array( $this, 'register_upload_post_type' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
		add_action( self::CRON_HOOK, array( $this, 'cleanup_expired_uploads' ) );
	}

	public static function activate() {
		self::instance()->register_upload_post_type();
		$storage = self::instance()->ensure_storage();
		if ( is_wp_error( $storage ) ) update_option( 'wcu_file_upload_storage_error', $storage->get_error_message(), false );
		else delete_option( 'wcu_file_upload_storage_error' );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

	public function register_upload_post_type() {
		register_post_type(
			self::UPLOAD_POST,
			array(
				'labels'          => array( 'name' => 'WCU Uploads' ),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => false,
				'map_meta_cap'    => true,
				'capability_type' => array( 'wcu_upload', 'wcu_uploads' ),
				'capabilities'    => array(
					'create_posts'       => 'do_not_allow',
					'edit_posts'         => 'manage_woocommerce',
					'edit_others_posts'  => 'manage_woocommerce',
					'publish_posts'      => 'manage_woocommerce',
					'read_private_posts' => 'manage_woocommerce',
					'delete_posts'       => 'manage_woocommerce',
				),
			)
		);
	}

	public function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		add_action( 'admin_menu', array( $this, 'admin_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'storage_error_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		add_action( 'woocommerce_product_options_advanced', array( $this, 'product_upload_option' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_upload_option' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_upload_field' ), 12 );
		add_shortcode( 'wcu_upload', array( $this, 'shortcode' ) );

		add_action( 'wp_ajax_wcu_upload', array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_nopriv_wcu_upload', array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_wcu_remove_upload', array( $this, 'ajax_remove_upload' ) );
		add_action( 'wp_ajax_nopriv_wcu_remove_upload', array( $this, 'ajax_remove_upload' ) );

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 5 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_uploads' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'create_order_line_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'bind_uploads_to_order' ) );
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render_customer_order_files' ), 10, 4 );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_admin_order_files' ), 10, 3 );
		add_filter( 'woocommerce_email_attachments', array( $this, 'email_attachments' ), 10, 4 );

		add_action( 'admin_post_wcu_download', array( $this, 'download_file' ) );
		add_action( 'admin_post_nopriv_wcu_download', array( $this, 'download_file' ) );
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'WCU File Upload requires WooCommerce to be installed and active.', 'wcu-file-upload' ) . '</p></div>';
	}

	public function storage_error_notice() {
		$message = get_option( 'wcu_file_upload_storage_error' );
		if ( $message && current_user_can( 'manage_woocommerce' ) ) echo '<div class="notice notice-error"><p><strong>WCU File Upload:</strong> ' . esc_html( $message ) . '</p></div>';
	}

	private function defaults() {
		return array(
			'enabled'          => 'yes',
			'required'         => 'no',
			'categories'       => array(),
			'max_files'        => 3,
			'max_size_mb'      => 20,
			'extensions'       => 'pdf,ai,eps,png,jpg,jpeg,webp',
			'title'            => 'Upload artwork / reference files',
			'description'      => 'PDF, AI, EPS, PNG, JPG or WEBP. Upload up to 3 files.',
			'retention_days'   => 7,
			'reserved_days'    => 30,
			'email_attachments'=> 'no',
			'link_expiry_days' => 30,
		);
	}

	private function settings() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), $this->defaults() );
	}

	public function register_settings() {
		register_setting( 'wcu_file_upload', self::OPTION_KEY, array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$defaults = $this->defaults();
		$output = array();
		$output['enabled'] = ! empty( $input['enabled'] ) ? 'yes' : 'no';
		$output['required'] = ! empty( $input['required'] ) ? 'yes' : 'no';
		$output['email_attachments'] = ! empty( $input['email_attachments'] ) ? 'yes' : 'no';
		$output['max_files'] = max( 1, min( 20, absint( $input['max_files'] ?? $defaults['max_files'] ) ) );
		$output['max_size_mb'] = max( 1, min( 500, absint( $input['max_size_mb'] ?? $defaults['max_size_mb'] ) ) );
		$output['retention_days'] = max( 1, min( 90, absint( $input['retention_days'] ?? $defaults['retention_days'] ) ) );
		$output['reserved_days'] = max( 7, min( 365, absint( $input['reserved_days'] ?? $defaults['reserved_days'] ) ) );
		$output['link_expiry_days'] = max( 1, min( 365, absint( $input['link_expiry_days'] ?? $defaults['link_expiry_days'] ) ) );
		$output['title'] = sanitize_text_field( $input['title'] ?? $defaults['title'] );
		$output['description'] = sanitize_textarea_field( $input['description'] ?? $defaults['description'] );
		$output['categories'] = array_values( array_filter( array_map( 'absint', (array) ( $input['categories'] ?? array() ) ) ) );
		$output['extensions'] = implode( ',', $this->sanitize_extensions( $input['extensions'] ?? $defaults['extensions'] ) );
		return $output;
	}

	private function dangerous_extensions() {
		return array( 'php','php3','php4','php5','php6','php7','php8','phtml','phar','inc','js','mjs','html','htm','xhtml','svg','exe','com','bat','cmd','sh','ps1','htaccess','cgi','pl','py','jar','asp','aspx','jsp' );
	}

	private function supported_extensions() {
		return array( 'pdf','ai','eps','png','jpg','jpeg','webp' );
	}

	private function sanitize_extensions( $value ) {
		$items = preg_split( '/[\s,;]+/', strtolower( (string) $value ) );
		$items = array_map( function( $ext ) { return preg_replace( '/[^a-z0-9]/', '', $ext ); }, $items );
		$items = array_diff( array_unique( array_filter( $items ) ), $this->dangerous_extensions() );
		$items = array_intersect( $items, $this->supported_extensions() );
		return $items ? array_values( $items ) : $this->supported_extensions();
	}

	public function admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'File Upload', 'wcu-file-upload' ),
			__( 'File Upload', 'wcu-file-upload' ),
			'manage_woocommerce',
			'wcu-file-upload',
			array( $this, 'settings_page' )
		);
	}

	public function admin_assets( $hook ) {
		if ( 'woocommerce_page_wcu-file-upload' === $hook ) {
			wp_enqueue_style( 'wcu-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), self::VERSION );
		}
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = $this->settings();
		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $categories ) ) $categories = array();
		?>
		<div class="wrap wcu-admin-wrap">
			<h1><?php esc_html_e( 'WooCommerce File Upload', 'wcu-file-upload' ); ?></h1>
			<p><?php esc_html_e( 'Securely collect customer artwork and reference files on product pages.', 'wcu-file-upload' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'wcu_file_upload' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Enable', 'wcu-file-upload' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 'yes' ); ?>> <?php esc_html_e( 'Enable customer uploads', 'wcu-file-upload' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Required', 'wcu-file-upload' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[required]" value="1" <?php checked( $s['required'], 'yes' ); ?>> <?php esc_html_e( 'Require at least one valid file before adding to cart', 'wcu-file-upload' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Product categories', 'wcu-file-upload' ); ?></th><td><select multiple size="8" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[categories][]" class="regular-text"><?php foreach ( $categories as $term ) : ?><option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( $term->term_id, $s['categories'], true ) ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Leave empty for all products. Selecting a parent category also includes its descendants.', 'wcu-file-upload' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Maximum files', 'wcu-file-upload' ); ?></th><td><input type="number" min="1" max="20" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_files]" value="<?php echo esc_attr( $s['max_files'] ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Maximum size per file', 'wcu-file-upload' ); ?></th><td><input type="number" min="1" max="500" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_size_mb]" value="<?php echo esc_attr( $s['max_size_mb'] ); ?>"> MB <p class="description"><?php echo esc_html( sprintf( __( 'Server limit: %s', 'wcu-file-upload' ), size_format( wp_max_upload_size() ) ) ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Allowed extensions', 'wcu-file-upload' ); ?></th><td><input class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[extensions]" value="<?php echo esc_attr( $s['extensions'] ); ?>"><p class="description"><?php esc_html_e( 'Supported: PDF, AI, EPS, PNG, JPG, JPEG, WEBP. Executable and active-content formats are always blocked.', 'wcu-file-upload' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Field title', 'wcu-file-upload' ); ?></th><td><input class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[title]" value="<?php echo esc_attr( $s['title'] ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Field description', 'wcu-file-upload' ); ?></th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[description]"><?php echo esc_textarea( $s['description'] ); ?></textarea></td></tr>
					<tr><th><?php esc_html_e( 'Temporary file retention', 'wcu-file-upload' ); ?></th><td><input type="number" min="1" max="90" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>"> <?php esc_html_e( 'days', 'wcu-file-upload' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Cart-reserved file retention', 'wcu-file-upload' ); ?></th><td><input type="number" min="7" max="365" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[reserved_days]" value="<?php echo esc_attr( $s['reserved_days'] ); ?>"> <?php esc_html_e( 'days', 'wcu-file-upload' ); ?><p class="description"><?php esc_html_e( 'Files already placed in a cart are kept longer than unclaimed uploads.', 'wcu-file-upload' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Customer link expiry', 'wcu-file-upload' ); ?></th><td><input type="number" min="1" max="365" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[link_expiry_days]" value="<?php echo esc_attr( $s['link_expiry_days'] ); ?>"> <?php esc_html_e( 'days', 'wcu-file-upload' ); ?><p class="description"><?php esc_html_e( 'Email download links are signed and expire. Logged-in order owners can still download afterward.', 'wcu-file-upload' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Email attachments', 'wcu-file-upload' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_attachments]" value="1" <?php checked( $s['email_attachments'], 'yes' ); ?>> <?php esc_html_e( 'Attach files to admin New Order email only', 'wcu-file-upload' ); ?></label><p class="description"><?php esc_html_e( 'Keep disabled for large files. Download links are always included.', 'wcu-file-upload' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<div class="wcu-security-note"><strong><?php esc_html_e( 'Security note:', 'wcu-file-upload' ); ?></strong> <?php esc_html_e( 'Files are stored with random names in a protected directory. For Nginx, block direct access to /wp-content/uploads/wcu-files/.', 'wcu-file-upload' ); ?></div>
		</div>
		<?php
	}

	public function product_upload_option() {
		woocommerce_wp_select(
			array(
				'id'          => self::PRODUCT_META,
				'label'       => __( 'Customer file upload', 'wcu-file-upload' ),
				'description' => __( 'Override the global/category setting for this product.', 'wcu-file-upload' ),
				'desc_tip'    => true,
				'options'     => array(
					'inherit' => __( 'Inherit global setting', 'wcu-file-upload' ),
					'enabled' => __( 'Force enabled', 'wcu-file-upload' ),
					'disabled'=> __( 'Force disabled', 'wcu-file-upload' ),
				),
				'value'       => get_post_meta( get_the_ID(), self::PRODUCT_META, true ) ?: 'inherit',
			)
		);
	}

	public function save_product_upload_option( $product_id ) {
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}
		$mode = isset( $_POST[ self::PRODUCT_META ] ) ? sanitize_key( wp_unslash( $_POST[ self::PRODUCT_META ] ) ) : 'inherit';
		if ( ! in_array( $mode, array( 'inherit','enabled','disabled' ), true ) ) {
			$mode = 'inherit';
		}
		update_post_meta( $product_id, self::PRODUCT_META, $mode );
	}

	private function is_enabled_for_product( $product_id ) {
		$product_id = absint( $product_id );
		$mode = get_post_meta( $product_id, self::PRODUCT_META, true );
		if ( 'disabled' === $mode ) return false;
		if ( 'enabled' === $mode ) return true;
		$s = $this->settings();
		if ( 'yes' !== $s['enabled'] ) return false;
		$selected = array_map( 'absint', (array) $s['categories'] );
		if ( empty( $selected ) ) return true;
		$product_terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $product_terms ) ) return false;
		foreach ( $product_terms as $term_id ) {
			if ( in_array( (int) $term_id, $selected, true ) ) return true;
			$ancestors = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
			if ( array_intersect( $selected, array_map( 'intval', $ancestors ) ) ) return true;
		}
		return false;
	}

	public function frontend_assets() {
		if ( ! is_product() ) return;
		$product_id = get_queried_object_id();
		if ( ! $this->is_enabled_for_product( $product_id ) ) return;
		$s = $this->settings();
		wp_enqueue_style( 'wcu-upload', plugins_url( 'assets/upload.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( 'wcu-upload', plugins_url( 'assets/upload.js', __FILE__ ), array(), self::VERSION, true );
		wp_localize_script( 'wcu-upload', 'wcuUpload', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
			'productId'    => $product_id,
			'maxFiles'     => (int) $s['max_files'],
			'maxBytes'     => min( (int) $s['max_size_mb'] * MB_IN_BYTES, wp_max_upload_size() ),
			'extensions'   => $this->sanitize_extensions( $s['extensions'] ),
			'messages'     => array(
				'tooMany'   => __( 'Too many files selected.', 'wcu-file-upload' ),
				'tooLarge'  => __( 'This file is larger than the allowed limit.', 'wcu-file-upload' ),
				'badType'   => __( 'This file type is not allowed.', 'wcu-file-upload' ),
				'uploading' => __( 'Uploading…', 'wcu-file-upload' ),
				'uploaded'  => __( 'Uploaded', 'wcu-file-upload' ),
				'failed'    => __( 'Upload failed.', 'wcu-file-upload' ),
				'remove'    => __( 'Remove', 'wcu-file-upload' ),
				'required'  => __( 'Please upload at least one valid file.', 'wcu-file-upload' ),
			),
		) );
	}

	public function shortcode() {
		ob_start();
		$this->render_upload_field();
		return ob_get_clean();
	}

	public function render_upload_field() {
		if ( $this->rendered || ! is_product() ) return;
		global $product;
		if ( ! $product || ! $this->is_enabled_for_product( $product->get_id() ) ) return;
		$this->rendered = true;
		$s = $this->settings();
		$required = 'yes' === $s['required'];
		?>
		<div class="wcu-uploader" data-required="<?php echo $required ? '1' : '0'; ?>">
			<div class="wcu-heading"><?php echo esc_html( $s['title'] ); ?><?php if ( $required ) : ?> <span class="required" aria-hidden="true">*</span><?php endif; ?></div>
			<?php if ( $s['description'] ) : ?><p class="wcu-description"><?php echo nl2br( esc_html( $s['description'] ) ); ?></p><?php endif; ?>
			<div class="wcu-dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Choose files to upload', 'wcu-file-upload' ); ?>">
				<span class="wcu-drop-icon" aria-hidden="true">↑</span>
				<strong><?php esc_html_e( 'Drop files here', 'wcu-file-upload' ); ?></strong>
				<span><?php esc_html_e( 'or click to browse', 'wcu-file-upload' ); ?></span>
				<input class="wcu-file-input" type="file" multiple hidden accept="<?php echo esc_attr( implode( ',', array_map( function( $e ) { return '.' . $e; }, $this->sanitize_extensions( $s['extensions'] ) ) ) ); ?>">
			</div>
			<ul class="wcu-file-list" aria-live="polite"></ul>
			<div class="wcu-error" role="alert" hidden></div>
			<input type="hidden" name="wcu_upload_tokens" class="wcu-upload-tokens" value="">
		</div>
		<?php
	}

	private function session_hash() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'wcu_touched', time() );
			$id = (string) WC()->session->get_customer_id();
		} else {
			$id = wp_get_session_token();
		}
		return hash_hmac( 'sha256', $id ?: 'anonymous', wp_salt( 'nonce' ) );
	}

	private function rate_limit_ok() {
		$key_source = $this->session_hash() . '|' . ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key = 'wcu_rate_' . substr( hash( 'sha256', $key_source ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= 40 ) return false;
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	public function ajax_upload() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! $this->rate_limit_ok() ) wp_send_json_error( array( 'message' => __( 'Too many upload attempts. Please wait and try again.', 'wcu-file-upload' ) ), 429 );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id || ! $this->is_enabled_for_product( $product_id ) ) wp_send_json_error( array( 'message' => __( 'Uploads are not enabled for this product.', 'wcu-file-upload' ) ), 403 );
		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) wp_send_json_error( array( 'message' => __( 'No file received.', 'wcu-file-upload' ) ), 400 );

		$s = $this->settings();
		$existing = $this->count_session_uploads( $product_id );
		if ( $existing >= (int) $s['max_files'] ) wp_send_json_error( array( 'message' => __( 'Maximum file count reached.', 'wcu-file-upload' ) ), 400 );

		$file = $_FILES['file'];
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) wp_send_json_error( array( 'message' => $this->upload_error_message( (int) $file['error'] ) ), 400 );
		$actual_size = filesize( $file['tmp_name'] );
		$max_bytes = min( (int) $s['max_size_mb'] * MB_IN_BYTES, wp_max_upload_size() );
		if ( false === $actual_size || $actual_size < 1 || $actual_size > $max_bytes ) wp_send_json_error( array( 'message' => sprintf( __( 'File exceeds the %s limit.', 'wcu-file-upload' ), size_format( $max_bytes ) ) ), 400 );

		$original = sanitize_file_name( wp_unslash( $file['name'] ) );
		$ext = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
		$allowed = $this->sanitize_extensions( $s['extensions'] );
		if ( ! in_array( $ext, $allowed, true ) || in_array( $ext, $this->dangerous_extensions(), true ) ) wp_send_json_error( array( 'message' => __( 'File extension is not allowed.', 'wcu-file-upload' ) ), 400 );
		if ( ! $this->valid_magic_bytes( $file['tmp_name'], $ext ) ) wp_send_json_error( array( 'message' => __( 'File content does not match its extension.', 'wcu-file-upload' ) ), 400 );

		$dir = $this->ensure_storage();
		if ( is_wp_error( $dir ) ) { update_option( 'wcu_file_upload_storage_error', $dir->get_error_message(), false ); wp_send_json_error( array( 'message' => $dir->get_error_message() ), 500 ); }
		delete_option( 'wcu_file_upload_storage_error' );
		$stored = 'wcu-' . gmdate( 'Ymd' ) . '-' . strtolower( wp_generate_password( 32, false, false ) ) . '.' . ( 'jpeg' === $ext ? 'jpg' : $ext );
		$path = trailingslashit( $dir ) . $stored;
		if ( ! @move_uploaded_file( $file['tmp_name'], $path ) ) wp_send_json_error( array( 'message' => __( 'Could not securely store the uploaded file.', 'wcu-file-upload' ) ), 500 );
		@chmod( $path, 0640 );

		$upload_id = wp_insert_post( array( 'post_type' => self::UPLOAD_POST, 'post_status' => 'publish', 'post_title' => $stored ), true );
		if ( is_wp_error( $upload_id ) ) { @unlink( $path ); wp_send_json_error( array( 'message' => __( 'Could not record the uploaded file.', 'wcu-file-upload' ) ), 500 ); }
		$token = wp_generate_password( 48, false, false );
		update_post_meta( $upload_id, '_wcu_token_hash', hash( 'sha256', $token ) );
		update_post_meta( $upload_id, '_wcu_session_hash', $this->session_hash() );
		update_post_meta( $upload_id, '_wcu_product_id', $product_id );
		update_post_meta( $upload_id, '_wcu_original_name', $original );
		update_post_meta( $upload_id, '_wcu_stored_name', $stored );
		update_post_meta( $upload_id, '_wcu_size', $actual_size );
		update_post_meta( $upload_id, '_wcu_extension', $ext );
		update_post_meta( $upload_id, '_wcu_status', 'temporary' );
		update_post_meta( $upload_id, '_wcu_attached', 'no' );

		wp_send_json_success( array( 'token' => $upload_id . '.' . $token, 'name' => $original, 'size' => size_format( $actual_size ) ) );
	}

	public function ajax_remove_upload() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$upload = $this->validate_token( $token );
		if ( is_wp_error( $upload ) ) wp_send_json_error( array( 'message' => $upload->get_error_message() ), 400 );
		if ( 'yes' === get_post_meta( $upload->ID, '_wcu_attached', true ) || $this->is_upload_in_current_cart( $upload->ID ) ) wp_send_json_error( array( 'message' => __( 'This file is already reserved by a cart or order.', 'wcu-file-upload' ) ), 409 );
		$this->delete_upload( $upload->ID );
		wp_send_json_success();
	}

	private function is_upload_in_current_cart( $upload_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) return false;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( in_array( absint( $upload_id ), array_map( 'absint', (array) ( $cart_item['wcu_upload_ids'] ?? array() ) ), true ) ) return true;
		}
		return false;
	}

	private function count_session_uploads( $product_id ) {
		$q = new WP_Query( array(
			'post_type' => self::UPLOAD_POST, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 21,
			'meta_query' => array(
				array( 'key' => '_wcu_session_hash', 'value' => $this->session_hash() ),
				array( 'key' => '_wcu_product_id', 'value' => absint( $product_id ) ),
				array( 'key' => '_wcu_status', 'value' => 'temporary' ),
			),
		) );
		return (int) $q->found_posts;
	}

	private function valid_magic_bytes( $path, $ext ) {
		if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
			$info = @getimagesize( $path );
			$max_pixels = (int) apply_filters( 'wcu_max_image_pixels', 80000000 );
			if ( ! $info || empty( $info[0] ) || empty( $info[1] ) || (int) $info[0] * (int) $info[1] > $max_pixels ) return false;
		}
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) return false;
		$head = fread( $fh, 16 );
		fclose( $fh );
		if ( false === $head ) return false;
		switch ( $ext ) {
			case 'jpg': case 'jpeg': return 0 === strncmp( $head, "\xFF\xD8\xFF", 3 );
			case 'png': return 0 === strncmp( $head, "\x89PNG\r\n\x1A\n", 8 );
			case 'webp': return 0 === strncmp( $head, 'RIFF', 4 ) && 'WEBP' === substr( $head, 8, 4 );
			case 'pdf': return 0 === strncmp( $head, '%PDF-', 5 );
			case 'ai': return 0 === strncmp( $head, '%PDF-', 5 ) || 0 === strncmp( $head, '%!PS', 4 );
			case 'eps': return 0 === strncmp( $head, '%!PS', 4 );
		}
		return false;
	}

	private function upload_error_message( $code ) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE => __( 'The file exceeds the server upload limit.', 'wcu-file-upload' ),
			UPLOAD_ERR_FORM_SIZE => __( 'The file exceeds the form upload limit.', 'wcu-file-upload' ),
			UPLOAD_ERR_PARTIAL => __( 'The file was only partially uploaded.', 'wcu-file-upload' ),
			UPLOAD_ERR_NO_FILE => __( 'No file was uploaded.', 'wcu-file-upload' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'The server temporary directory is missing.', 'wcu-file-upload' ),
			UPLOAD_ERR_CANT_WRITE => __( 'The server could not write the file.', 'wcu-file-upload' ),
			UPLOAD_ERR_EXTENSION => __( 'A server extension stopped the upload.', 'wcu-file-upload' ),
		);
		return $messages[ $code ] ?? __( 'Upload failed.', 'wcu-file-upload' );
	}

	private function parse_tokens_from_request() {
		$raw = sanitize_text_field( wp_unslash( $_POST['wcu_upload_tokens'] ?? '' ) );
		return array_values( array_unique( array_filter( explode( ',', $raw ) ) ) );
	}

	private function validate_token( $token, $product_id = 0, $require_session = true ) {
		if ( ! preg_match( '/^(\d+)\.([A-Za-z0-9]+)$/', $token, $m ) ) return new WP_Error( 'bad_token', __( 'Invalid upload token.', 'wcu-file-upload' ) );
		$post = get_post( absint( $m[1] ) );
		if ( ! $post || self::UPLOAD_POST !== $post->post_type || 'publish' !== $post->post_status ) return new WP_Error( 'missing_upload', __( 'Uploaded file was not found.', 'wcu-file-upload' ) );
		if ( ! hash_equals( (string) get_post_meta( $post->ID, '_wcu_token_hash', true ), hash( 'sha256', $m[2] ) ) ) return new WP_Error( 'bad_token', __( 'Invalid upload token.', 'wcu-file-upload' ) );
		if ( $require_session && ! hash_equals( (string) get_post_meta( $post->ID, '_wcu_session_hash', true ), $this->session_hash() ) ) return new WP_Error( 'bad_session', __( 'This upload belongs to another session.', 'wcu-file-upload' ) );
		if ( $product_id && absint( get_post_meta( $post->ID, '_wcu_product_id', true ) ) !== absint( $product_id ) ) return new WP_Error( 'bad_product', __( 'This upload belongs to another product.', 'wcu-file-upload' ) );
		return $post;
	}

	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		$settings_id = $product_id;
		if ( ! $this->is_enabled_for_product( $settings_id ) ) return $passed;
		$tokens = $this->parse_tokens_from_request();
		$s = $this->settings();
		if ( 'yes' === $s['required'] && empty( $tokens ) ) { wc_add_notice( __( 'Please upload at least one file.', 'wcu-file-upload' ), 'error' ); return false; }
		if ( count( $tokens ) > (int) $s['max_files'] ) { wc_add_notice( __( 'Too many uploaded files.', 'wcu-file-upload' ), 'error' ); return false; }
		foreach ( $tokens as $token ) {
			$upload = $this->validate_token( $token, $settings_id );
			if ( is_wp_error( $upload ) ) { wc_add_notice( $upload->get_error_message(), 'error' ); return false; }
			if ( 'yes' === get_post_meta( $upload->ID, '_wcu_attached', true ) ) { wc_add_notice( __( 'An uploaded file has already been used.', 'wcu-file-upload' ), 'error' ); return false; }
		}
		return $passed;
	}

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		$settings_id = $product_id;
		if ( ! $this->is_enabled_for_product( $settings_id ) ) return $cart_item_data;
		$tokens = $this->parse_tokens_from_request();
		$ids = array();
		foreach ( $tokens as $token ) {
			$upload = $this->validate_token( $token, $settings_id );
			if ( ! is_wp_error( $upload ) ) $ids[] = $upload->ID;
		}
		if ( $ids ) {
			$ids = array_values( array_unique( $ids ) );
			$cart_item_data['wcu_upload_ids'] = $ids;
			$cart_item_data['wcu_upload_key'] = wp_generate_uuid4();
			foreach ( $ids as $upload_id ) {
				update_post_meta( $upload_id, '_wcu_status', 'reserved' );
				update_post_meta( $upload_id, '_wcu_reserved_at', time() );
			}
		}
		return $cart_item_data;
	}

	public function display_cart_item_data( $item_data, $cart_item ) {
		foreach ( (array) ( $cart_item['wcu_upload_ids'] ?? array() ) as $upload_id ) {
			$name = get_post_meta( $upload_id, '_wcu_original_name', true );
			$size = absint( get_post_meta( $upload_id, '_wcu_size', true ) );
			if ( $name ) $item_data[] = array( 'key' => __( 'Uploaded file', 'wcu-file-upload' ), 'value' => esc_html( $name . ' (' . size_format( $size ) . ')' ) );
		}
		return $item_data;
	}

	private function is_upload_bound_to_awaiting_order( $upload_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) return false;
		$order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
		if ( ! $order_id || absint( get_post_meta( $upload_id, '_wcu_order_id', true ) ) !== $order_id ) return false;
		$order = wc_get_order( $order_id );
		return $order && $this->order_contains_upload( $order, $upload_id );
	}

	public function validate_cart_uploads() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
		$s = $this->settings();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = absint( $cart_item['product_id'] ?? 0 );
			if ( ! $product_id || ! $this->is_enabled_for_product( $product_id ) ) continue;
			$ids = array_values( array_filter( array_map( 'absint', (array) ( $cart_item['wcu_upload_ids'] ?? array() ) ) ) );
			if ( 'yes' === $s['required'] && ! $ids ) {
				wc_add_notice( __( 'A product in your cart requires at least one uploaded file.', 'wcu-file-upload' ), 'error' );
				continue;
			}
			if ( count( $ids ) > (int) $s['max_files'] ) {
				wc_add_notice( __( 'A product in your cart has too many uploaded files.', 'wcu-file-upload' ), 'error' );
				continue;
			}
			foreach ( $ids as $upload_id ) {
				$post = get_post( $upload_id );
				$valid = $post && self::UPLOAD_POST === $post->post_type
					&& absint( get_post_meta( $upload_id, '_wcu_product_id', true ) ) === $product_id
					&& ( 'yes' !== get_post_meta( $upload_id, '_wcu_attached', true ) || $this->is_upload_bound_to_awaiting_order( $upload_id ) )
					&& $this->upload_path( $upload_id );
				if ( ! $valid ) {
					wc_add_notice( __( 'An uploaded file in your cart is missing or no longer valid. Please remove the product and upload the file again.', 'wcu-file-upload' ), 'error' );
					break;
				}
			}
		}
	}

	public function create_order_line_item( $item, $cart_item_key, $values, $order ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) ( $values['wcu_upload_ids'] ?? array() ) ) ) );
		if ( $ids ) $item->add_meta_data( self::ITEM_META_IDS, wp_json_encode( $ids ), true );
	}

	public function bind_uploads_to_order( $order ) {
		if ( ! $order instanceof WC_Order || ! $order->get_id() ) return;
		foreach ( $order->get_items() as $item_id => $item ) {
			foreach ( $this->item_upload_ids( $item ) as $upload_id ) {
				if ( ! $this->upload_path( $upload_id ) ) continue;
				update_post_meta( $upload_id, '_wcu_attached', 'yes' );
				update_post_meta( $upload_id, '_wcu_status', 'ordered' );
				update_post_meta( $upload_id, '_wcu_order_id', $order->get_id() );
				update_post_meta( $upload_id, '_wcu_order_item_id', absint( $item_id ) );
			}
		}
	}

	private function item_upload_ids( $item ) {
		$raw = $item->get_meta( self::ITEM_META_IDS, true );
		$ids = json_decode( (string) $raw, true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
	}

	private function customer_download_url( $upload_id, $order ) {
		$expiry = time() + ( (int) $this->settings()['link_expiry_days'] * DAY_IN_SECONDS );
		$key = $order->get_order_key();
		$sig = hash_hmac( 'sha256', $upload_id . '|' . $order->get_id() . '|' . $key . '|' . $expiry, wp_salt( 'auth' ) );
		return add_query_arg( array( 'action' => 'wcu_download', 'upload_id' => $upload_id, 'order_id' => $order->get_id(), 'order_key' => $key, 'expires' => $expiry, 'sig' => $sig ), admin_url( 'admin-post.php' ) );
	}

	private function admin_download_url( $upload_id, $order_id ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'wcu_download', 'upload_id' => $upload_id, 'order_id' => $order_id ), admin_url( 'admin-post.php' ) ), 'wcu_download_' . $upload_id );
	}

	public function render_customer_order_files( $item_id, $item, $order, $plain_text ) {
		$ids = $this->item_upload_ids( $item );
		if ( ! $ids ) return;
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Uploaded files:', 'wcu-file-upload' ) . "\n";
			foreach ( $ids as $id ) {
				$name = get_post_meta( $id, '_wcu_original_name', true );
				$size = absint( get_post_meta( $id, '_wcu_size', true ) );
				echo '- ' . wp_strip_all_tags( $name ) . ' (' . size_format( $size ) . '): ' . esc_url_raw( $this->customer_download_url( $id, $order ) ) . "\n";
			}
		} else {
			echo '<div class="wcu-order-files"><strong>' . esc_html__( 'Uploaded files:', 'wcu-file-upload' ) . '</strong><ul>';
			foreach ( $ids as $id ) {
				$name = get_post_meta( $id, '_wcu_original_name', true );
				$size = absint( get_post_meta( $id, '_wcu_size', true ) );
				echo '<li><a href="' . esc_url( $this->customer_download_url( $id, $order ) ) . '">' . esc_html( $name ) . '</a> <small>(' . esc_html( size_format( $size ) ) . ')</small></li>';
			}
			echo '</ul></div>';
		}
	}

	public function render_admin_order_files( $item_id, $item, $product ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		$ids = $this->item_upload_ids( $item );
		if ( ! $ids ) return;
		$order_id = $item->get_order_id();
		echo '<div class="wcu-admin-order-files"><strong>' . esc_html__( 'Uploaded files:', 'wcu-file-upload' ) . '</strong><ul>';
		foreach ( $ids as $id ) {
			$name = get_post_meta( $id, '_wcu_original_name', true );
			$size = absint( get_post_meta( $id, '_wcu_size', true ) );
			echo '<li><a href="' . esc_url( $this->admin_download_url( $id, $order_id ) ) . '">' . esc_html( $name ) . '</a> <small>(' . esc_html( size_format( $size ) ) . ')</small></li>';
		}
		echo '</ul></div>';
	}

	public function email_attachments( $attachments, $email_id, $order, $email ) {
		if ( 'yes' !== $this->settings()['email_attachments'] || 'new_order' !== $email_id || ! $order instanceof WC_Order ) return $attachments;
		$total = 0;
		$limit = (int) apply_filters( 'wcu_email_attachment_max_bytes', 20 * MB_IN_BYTES, $order );
		foreach ( $order->get_items() as $item ) {
			foreach ( $this->item_upload_ids( $item ) as $upload_id ) {
				$path = $this->upload_path( $upload_id );
				$size = $path && is_readable( $path ) ? (int) filesize( $path ) : 0;
				if ( $size > 0 && $total + $size <= $limit ) { $attachments[] = $path; $total += $size; }
			}
		}
		return array_values( array_unique( $attachments ) );
	}

	private function order_contains_upload( $order, $upload_id ) {
		foreach ( $order->get_items() as $item ) {
			if ( in_array( absint( $upload_id ), $this->item_upload_ids( $item ), true ) ) return true;
		}
		return false;
	}

	public function download_file() {
		$upload_id = absint( $_GET['upload_id'] ?? 0 );
		$order_id = absint( $_GET['order_id'] ?? 0 );
		$order = wc_get_order( $order_id );
		if ( ! $upload_id || ! $order || absint( get_post_meta( $upload_id, '_wcu_order_id', true ) ) !== $order_id || ! $this->order_contains_upload( $order, $upload_id ) ) wp_die( esc_html__( 'Invalid download request.', 'wcu-file-upload' ), '', array( 'response' => 403 ) );
		$allowed = false;
		if ( current_user_can( 'manage_woocommerce' ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wcu_download_' . $upload_id ) ) {
			$allowed = true;
		} elseif ( is_user_logged_in() && $order->get_user_id() && get_current_user_id() === (int) $order->get_user_id() ) {
			$allowed = true;
		} else {
			$key = sanitize_text_field( wp_unslash( $_GET['order_key'] ?? '' ) );
			$expires = absint( $_GET['expires'] ?? 0 );
			$sig = sanitize_text_field( wp_unslash( $_GET['sig'] ?? '' ) );
			$expected = hash_hmac( 'sha256', $upload_id . '|' . $order_id . '|' . $key . '|' . $expires, wp_salt( 'auth' ) );
			if ( $expires >= time() && hash_equals( $order->get_order_key(), $key ) && hash_equals( $expected, $sig ) ) $allowed = true;
		}
		if ( ! $allowed ) wp_die( esc_html__( 'This download link is invalid or has expired.', 'wcu-file-upload' ), '', array( 'response' => 403 ) );
		$path = $this->upload_path( $upload_id );
		if ( ! $path || ! is_readable( $path ) ) wp_die( esc_html__( 'File not found.', 'wcu-file-upload' ), '', array( 'response' => 404 ) );
		$name = sanitize_file_name( get_post_meta( $upload_id, '_wcu_original_name', true ) ?: basename( $path ) );
		$fallback = preg_replace( '/[^A-Za-z0-9._-]/', '_', $name );
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . filesize( $path ) );
		while ( ob_get_level() ) ob_end_clean();
		readfile( $path );
		exit;
	}

	private function storage_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) return false;
		return trailingslashit( $uploads['basedir'] ) . 'wcu-files';
	}

	private function ensure_storage() {
		$dir = $this->storage_dir();
		if ( ! $dir || ! wp_mkdir_p( $dir ) || ! is_dir( $dir ) || ! is_writable( $dir ) ) return new WP_Error( 'storage', __( 'Upload storage directory could not be created or is not writable.', 'wcu-file-upload' ) );
		$files = array(
			'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", 
			'index.php' => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>",
		);
		foreach ( $files as $name => $content ) {
			$target = trailingslashit( $dir ) . $name;
			if ( false === @file_put_contents( $target, $content, LOCK_EX ) ) return new WP_Error( 'storage_protection', __( 'Upload directory protection files could not be written.', 'wcu-file-upload' ) );
		}
		return $dir;
	}

	private function upload_path( $upload_id ) {
		$stored = basename( (string) get_post_meta( $upload_id, '_wcu_stored_name', true ) );
		$dir = $this->storage_dir();
		if ( ! $stored || ! $dir ) return false;
		$path = trailingslashit( $dir ) . $stored;
		return file_exists( $path ) ? $path : false;
	}

	private function delete_upload( $upload_id ) {
		$path = $this->upload_path( $upload_id );
		if ( $path && is_file( $path ) ) @unlink( $path );
		wp_delete_post( $upload_id, true );
	}

	public function cleanup_expired_uploads() {
		$s = $this->settings();
		$deadline = microtime( true ) + 15;
		$queries = array(
			array(
				'date_query' => array( array( 'before' => gmdate( 'Y-m-d H:i:s', time() - (int) $s['retention_days'] * DAY_IN_SECONDS ), 'inclusive' => true ) ),
				'meta_query' => array( array( 'key' => '_wcu_status', 'value' => 'temporary' ) ),
			),
			array(
				'meta_query' => array(
					array( 'key' => '_wcu_status', 'value' => 'reserved' ),
					array( 'key' => '_wcu_reserved_at', 'value' => time() - (int) $s['reserved_days'] * DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC' ),
				),
			),
		);
		foreach ( $queries as $extra ) {
			for ( $batch = 0; $batch < 5 && microtime( true ) < $deadline; $batch++ ) {
				$args = array_merge( array(
					'post_type' => self::UPLOAD_POST, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 100,
					'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
				), $extra );
				$q = new WP_Query( $args );
				if ( ! $q->posts ) break;
				foreach ( $q->posts as $upload_id ) {
					if ( microtime( true ) >= $deadline ) break 3;
					$this->delete_upload( $upload_id );
				}
			}
		}
	}
}

register_activation_hook( __FILE__, array( 'WCU_File_Upload', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCU_File_Upload', 'deactivate' ) );
WCU_File_Upload::instance();
