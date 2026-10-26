<?php
/**
 * Vista de la pantalla «Formularios listos».
 *
 * Variables disponibles: $catalogo, $estado, $avisos.
 *
 * @package SWPagos
 */

defined( 'ABSPATH' ) || exit;

/** @var array<string,array{titulo:string,descripcion:string,para:string,pago:bool,frecuencias:string[]}> $catalogo */
/** @var array<string,int> $estado */
/** @var array<int,array{tipo:string,texto:string}> $avisos */

/**
 * Pinta las tarjetas de un grupo (con cobro o sin cobro).
 *
 * Es un cierre y no una función: en este módulo no se declara nada en el ámbito
 * global, ni una función.
 */
$swpagos_grupo = static function ( array $catalogo, array $estado, bool $con_pago ): void {
	echo '<div class="swpagos-biblioteca">';

	foreach ( $catalogo as $clave => $info ) {
		if ( (bool) $info['pago'] !== $con_pago ) {
			continue;
		}

		$guardado = (int) ( $estado[ $clave ] ?? 0 ) > 0;
		?>
		<div class="swpagos-tarjeta">
			<h3><?php echo esc_html( (string) $info['titulo'] ); ?></h3>

			<p class="swpagos-para"><?php echo esc_html( (string) $info['para'] ); ?></p>
			<p><?php echo esc_html( (string) $info['descripcion'] ); ?></p>

			<form method="post" class="swpagos-crear">
				<?php wp_nonce_field( 'swpagos_biblioteca_crear_pagina' ); ?>
				<input type="hidden" name="swpagos_accion" value="crear_pagina">
				<input type="hidden" name="swpagos_variante" value="<?php echo esc_attr( (string) $clave ); ?>">
				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Título de la página', 'sw-pagos' ); ?></span>
					<input type="text" name="swpagos_titulo" placeholder="<?php esc_attr_e( 'Cómo quieres llamar la página', 'sw-pagos' ); ?>" value="">
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Crear la página', 'sw-pagos' ); ?></button>
			</form>

			<p class="description swpagos-nota-accion">
				<?php esc_html_e( 'Crea la página en borrador con el formulario puesto y, si cobra, su plantilla de pago ya enlazada. Es la vía recomendada: no hay que enlazar nada a mano.', 'sw-pagos' ); ?>
			</p>

			<details class="swpagos-otras">
				<summary><?php esc_html_e( 'Ponerlo en una página que ya existe', 'sw-pagos' ); ?></summary>

				<p>
					<button type="button" class="button swpagos-copiar" data-variante="<?php echo esc_attr( (string) $clave ); ?>">
						<?php esc_html_e( 'Copiar', 'sw-pagos' ); ?>
					</button>
					<?php esc_html_e( 'Deja el formulario en el portapapeles. Abre la página con Elementor y pega con Ctrl/Cmd + V.', 'sw-pagos' ); ?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'swpagos_biblioteca_registrar' ); ?>
					<input type="hidden" name="swpagos_accion" value="registrar">
					<input type="hidden" name="swpagos_variante" value="<?php echo esc_attr( (string) $clave ); ?>">
					<button type="submit" class="button">
						<?php
						echo $guardado
							? esc_html__( 'Actualizar en «Mis plantillas»', 'sw-pagos' )
							: esc_html__( 'Guardar en «Mis plantillas»', 'sw-pagos' );
						?>
					</button>
					<?php esc_html_e( 'Lo deja dentro de Elementor, en el icono de carpeta → «Mis plantillas». Útil si el pegado no funciona en tu navegador.', 'sw-pagos' ); ?>
					<?php if ( $guardado ) : ?>
						<em><?php esc_html_e( 'Ya está guardado.', 'sw-pagos' ); ?></em>
					<?php endif; ?>
				</form>

				<p class="description">
					<?php esc_html_e( 'Por estas dos vías el formulario no trae plantilla de pago: si cobra, tendrás que crearla y escribir en ella el mismo identificador de formulario.', 'sw-pagos' ); ?>
				</p>
			</details>
		</div>
		<?php
	}

	echo '</div>';
};
?>
<div class="wrap swpagos-wrap">
	<h1><?php esc_html_e( 'Formularios listos', 'sw-pagos' ); ?></h1>

	<p class="swpagos-entradilla">
		<?php esc_html_e( 'Elige el formulario que necesitas y el módulo te lo entrega armado: los campos, el cobro con Stripe y la conexión con HubSpot ya puestos. Luego solo pegas la URL de tu formulario de HubSpot y lo escaneas.', 'sw-pagos' ); ?>
	</p>

	<?php foreach ( $avisos as $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( $aviso['tipo'] ); ?> is-dismissible">
			<p><?php echo wp_kses_post( $aviso['texto'] ); ?></p>
		</div>
	<?php endforeach; ?>

	<div id="swpagos-aviso-copia" class="notice notice-success" style="display:none"><p></p></div>

	<h2 class="swpagos-grupo"><?php esc_html_e( 'Sin cobro', 'sw-pagos' ); ?></h2>
	<?php $swpagos_grupo( $catalogo, $estado, false ); ?>

	<h2 class="swpagos-grupo"><?php esc_html_e( 'Con cobro por Stripe', 'sw-pagos' ); ?></h2>
	<?php $swpagos_grupo( $catalogo, $estado, true ); ?>

	<h2><?php esc_html_e( 'Qué hay que hacer después', 'sw-pagos' ); ?></h2>
	<ol class="swpagos-pasos">
		<li>
			<strong><?php esc_html_e( 'Conectar HubSpot.', 'sw-pagos' ); ?></strong>
			<?php esc_html_e( 'Abre la página con Elementor, pulsa el campo «HubSpot (configuración)» del formulario, pega ahí la URL de tu formulario de HubSpot y pulsa «Escanear». Se rellenan solos el portal, el formulario y el nombre de cada propiedad.', 'sw-pagos' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Poner el importe.', 'sw-pagos' ); ?></strong>
			<?php
			printf(
				/* translators: %s: enlace a las plantillas de pago. */
				esc_html__( 'Solo en los formularios con cobro: en su %s se decide cuánto se cobra, el mínimo y el máximo, y qué correo recibe la persona.', 'sw-pagos' ),
				'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . \SWPagos\Core\Compat::CPT_PLANTILLA ) ) . '">' . esc_html__( 'plantilla de pago', 'sw-pagos' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput
			);
			?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Probar y publicar.', 'sw-pagos' ); ?></strong>
			<?php esc_html_e( 'El formulario nace en modo prueba: puedes donar con una tarjeta de prueba de Stripe sin mover dinero. Cuando todo funcione, enciende el modo real en la acción de cobro del formulario.', 'sw-pagos' ); ?>
		</li>
	</ol>
</div>
