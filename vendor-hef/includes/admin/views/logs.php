<?php
/**
 * Vista: listado de registros.
 *
 * @package HubSpot_Elementor_Form
 */

defined( 'ABSPATH' ) || exit;

$hef_table = new \HEF\Admin\Logs_Table();
$hef_table->prepare_items();

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$hef_status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
$hef_search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
$hef_form   = isset( $_REQUEST['hef_form'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['hef_form'] ) ) : '';
$hef_page   = isset( $_REQUEST['hef_page'] ) ? esc_url_raw( wp_unslash( $_REQUEST['hef_page'] ) ) : '';
$hef_from   = isset( $_REQUEST['hef_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['hef_from'] ) ) : '';
$hef_to     = isset( $_REQUEST['hef_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['hef_to'] ) ) : '';
$hef_id     = isset( $_REQUEST['hef_id'] ) ? absint( $_REQUEST['hef_id'] ) : 0;
// phpcs:enable

$hef_forms = \HEF\Logger::distinct( 'form_name' );
$hef_pages = \HEF\Logger::distinct( 'page_url' );
?>
<div class="wrap hef-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Registros de envío a HubSpot', 'hubspot-elementor-form' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \HEF\Admin\Admin::PAGE_SET ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajustes', 'hubspot-elementor-form' ); ?></a>
	<hr class="wp-header-end">

	<?php \HEF\Admin\Admin::print_notice(); ?>

	<?php if ( $hef_id ) : ?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: %d: id del registro */
					esc_html__( 'Mostrando solo el registro #%d.', 'hubspot-elementor-form' ),
					(int) $hef_id
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \HEF\Admin\Admin::PAGE_LOGS ) ); ?>"><?php esc_html_e( 'Ver todos', 'hubspot-elementor-form' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<form method="get" class="hef-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( \HEF\Admin\Admin::PAGE_LOGS ); ?>">
		<input type="hidden" name="status" value="<?php echo esc_attr( $hef_status ); ?>">

		<?php $hef_table->search_box( __( 'Buscar', 'hubspot-elementor-form' ), 'hef-search' ); ?>

		<div class="hef-filter-bar">
			<label for="hef-filter-form"><?php esc_html_e( 'Formulario', 'hubspot-elementor-form' ); ?></label>
			<select name="hef_form" id="hef-filter-form">
				<option value=""><?php esc_html_e( 'Todos', 'hubspot-elementor-form' ); ?></option>
				<?php foreach ( $hef_forms as $hef_option ) : ?>
					<option value="<?php echo esc_attr( $hef_option ); ?>" <?php selected( $hef_form, $hef_option ); ?>>
						<?php echo esc_html( $hef_option ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="hef-filter-page"><?php esc_html_e( 'Página', 'hubspot-elementor-form' ); ?></label>
			<select name="hef_page" id="hef-filter-page">
				<option value=""><?php esc_html_e( 'Todas', 'hubspot-elementor-form' ); ?></option>
				<?php
				foreach ( $hef_pages as $hef_option ) :
					$hef_path = wp_parse_url( $hef_option, PHP_URL_PATH );
					?>
					<option value="<?php echo esc_attr( $hef_option ); ?>" <?php selected( $hef_page, $hef_option ); ?>>
						<?php echo esc_html( $hef_path ? $hef_path : $hef_option ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="hef-filter-from"><?php esc_html_e( 'Desde', 'hubspot-elementor-form' ); ?></label>
			<input type="date" name="hef_from" id="hef-filter-from" value="<?php echo esc_attr( $hef_from ); ?>">

			<label for="hef-filter-to"><?php esc_html_e( 'Hasta', 'hubspot-elementor-form' ); ?></label>
			<input type="date" name="hef_to" id="hef-filter-to" value="<?php echo esc_attr( $hef_to ); ?>">

			<?php submit_button( __( 'Filtrar', 'hubspot-elementor-form' ), '', '', false ); ?>

			<?php if ( $hef_form || $hef_page || $hef_from || $hef_to || $hef_search ) : ?>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . \HEF\Admin\Admin::PAGE_LOGS ) ); ?>">
					<?php esc_html_e( 'Quitar filtros', 'hubspot-elementor-form' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</form>

	<?php $hef_table->views(); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="hef_bulk">
		<input type="hidden" name="status" value="<?php echo esc_attr( $hef_status ); ?>">
		<input type="hidden" name="s" value="<?php echo esc_attr( $hef_search ); ?>">
		<input type="hidden" name="hef_form" value="<?php echo esc_attr( $hef_form ); ?>">
		<input type="hidden" name="hef_page" value="<?php echo esc_attr( $hef_page ); ?>">
		<input type="hidden" name="hef_from" value="<?php echo esc_attr( $hef_from ); ?>">
		<input type="hidden" name="hef_to" value="<?php echo esc_attr( $hef_to ); ?>">
		<input type="hidden" name="hef_id" value="<?php echo esc_attr( $hef_id ); ?>">
		<input type="hidden" name="paged" value="<?php echo esc_attr( $hef_table->get_pagenum() ); ?>">
		<?php
		wp_nonce_field( 'hef_bulk' );
		$hef_table->display();
		?>
	</form>
</div>
