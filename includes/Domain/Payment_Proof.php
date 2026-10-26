<?php
/**
 * Prueba de que un cobro fue confirmado por la pasarela.
 *
 * @package SWPagos
 */

namespace SWPagos\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Credencial que exige el repositorio para marcar una transacción como pagada.
 *
 * La regla es que solo el receptor de eventos verificados puede escribir
 * `pagada`. Un comentario no impide que alguien llame al método desde otro sitio;
 * exigir un objeto que únicamente puede construirse tras verificar una firma, sí.
 *
 * Quien implemente esta interfaz es responsable de no instanciarse sin haber
 * comprobado la firma de la pasarela.
 */
interface Payment_Proof {

	/**
	 * Identificador del evento en la pasarela.
	 *
	 * @return string
	 */
	public function event_id(): string;

	/**
	 * Tipo del evento.
	 *
	 * @return string
	 */
	public function tipo(): string;
}
