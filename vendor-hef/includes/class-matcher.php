<?php
/**
 * Emparejamiento automático entre campos de Elementor y propiedades de HubSpot.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF;

defined( 'ABSPATH' ) || exit;

class Matcher {

	/**
	 * Puntuación mínima para proponer un emparejamiento.
	 */
	const THRESHOLD = 62;

	/**
	 * Puntuación mínima para proponerlo como "revisar" sin aplicarlo.
	 */
	const SUGGEST = 30;

	/**
	 * Sinónimos ES/EN por propiedad estándar de HubSpot.
	 *
	 * @return array
	 */
	public static function synonyms() {
		$map = array(
			'email'          => array( 'email', 'correo', 'correoelectronico', 'mail', 'emailaddress', 'tuemail' ),
			'firstname'      => array( 'nombre', 'nombres', 'firstname', 'name', 'primernombre', 'tunombre' ),
			'lastname'       => array( 'apellido', 'apellidos', 'lastname', 'surname', 'segundonombre' ),
			'phone'          => array( 'telefono', 'tel', 'phone', 'celular', 'movil', 'whatsapp', 'numerodetelefono', 'contacto' ),
			'mobilephone'    => array( 'movil', 'celular', 'mobile', 'mobilephone' ),
			'company'        => array( 'empresa', 'compania', 'company', 'organizacion', 'negocio', 'razonsocial' ),
			'website'        => array( 'web', 'sitioweb', 'website', 'url', 'paginaweb' ),
			'jobtitle'       => array( 'cargo', 'puesto', 'jobtitle', 'position', 'rol' ),
			'address'        => array( 'direccion', 'address', 'domicilio', 'calle' ),
			'city'           => array( 'ciudad', 'city', 'localidad', 'poblacion' ),
			'state'          => array( 'estado', 'provincia', 'state', 'region', 'departamento' ),
			'zip'            => array( 'cp', 'codigopostal', 'zip', 'zipcode', 'postalcode' ),
			'country'        => array( 'pais', 'country', 'nacion' ),
			'message'        => array( 'mensaje', 'message', 'comentarios', 'comentario', 'consulta', 'dudas', 'observaciones' ),
			'industry'       => array( 'industria', 'sector', 'industry', 'rubro' ),
			'numemployees'   => array( 'empleados', 'numeroempleados', 'numemployees', 'tamanoempresa' ),
			'hs_lead_status' => array( 'estadolead', 'leadstatus', 'estado' ),
			'lifecyclestage' => array( 'etapa', 'ciclodevida', 'lifecyclestage' ),
			'fax'            => array( 'fax' ),
			'salutation'     => array( 'tratamiento', 'saludo', 'titulo' ),
		);

		/**
		 * Filtra el diccionario de sinónimos usado en el auto-mapeo.
		 *
		 * @param array $map Sinónimos.
		 */
		return apply_filters( 'hef/matcher_synonyms', $map );
	}

	/**
	 * Afinidad entre tipos de campo de Elementor y de HubSpot.
	 *
	 * @return array
	 */
	protected static function type_affinity() {
		return array(
			'email'    => array( 'email' ),
			'tel'      => array( 'phonenumber', 'phone', 'number' ),
			'number'   => array( 'number' ),
			'textarea' => array( 'textarea' ),
			'select'   => array( 'select', 'radio', 'dropdown' ),
			'radio'    => array( 'radio', 'select' ),
			'checkbox' => array( 'checkbox', 'booleancheckbox' ),
			'date'     => array( 'date' ),
			'url'      => array( 'text' ),
			'text'     => array( 'text', 'single_line_text' ),
			'acceptance' => array( 'booleancheckbox', 'checkbox' ),
		);
	}

	/**
	 * Empareja los campos del formulario de Elementor con las propiedades de HubSpot.
	 *
	 * @param array $elementor_fields Lista de arrays: id, label, type, required, current.
	 * @param array $hubspot_fields   Lista devuelta por Scanner::normalize_fields().
	 * @param bool  $overwrite        Sobrescribir mapeos ya escritos por el usuario.
	 * @return array {mapping, unmatched, kept, obsolete, required_updates}
	 */
	public static function match( array $elementor_fields, array $hubspot_fields, $overwrite = false ) {
		$pairs = array();
		$kept  = array();

		$taken_props  = array();
		$locked_field = array();

		// Los mapeos existentes se respetan salvo que se pida sobrescribir.
		foreach ( $elementor_fields as $field ) {
			$current = isset( $field['current'] ) ? trim( (string) $field['current'] ) : '';
			if ( '' !== $current && ! $overwrite ) {
				$locked_field[ $field['id'] ] = true;
				$taken_props[ $current ]      = true;
				$kept[]                       = array(
					'id'       => $field['id'],
					'property' => $current,
				);
			}
		}

		foreach ( $elementor_fields as $field ) {
			if ( isset( $locked_field[ $field['id'] ] ) ) {
				continue;
			}

			foreach ( $hubspot_fields as $hs ) {
				$score = self::score( $field, $hs );

				if ( $score >= self::THRESHOLD ) {
					$pairs[] = array(
						'id'       => $field['id'],
						'property' => $hs['name'],
						'label'    => $hs['label'],
						'object'   => $hs['object'],
						'required' => ! empty( $hs['required'] ),
						'score'    => $score,
					);
				}
			}
		}

		// Asignación voraz: primero los emparejamientos más seguros.
		usort(
			$pairs,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$mapping = array();

		foreach ( $pairs as $pair ) {
			if ( isset( $mapping[ $pair['id'] ] ) || isset( $taken_props[ $pair['property'] ] ) ) {
				continue;
			}
			$mapping[ $pair['id'] ]        = $pair;
			$taken_props[ $pair['property'] ] = true;
		}

		// Campos de Elementor que se han quedado sin propiedad.
		$free = array();
		foreach ( $elementor_fields as $field ) {
			if ( ! isset( $mapping[ $field['id'] ] ) && ! isset( $locked_field[ $field['id'] ] ) ) {
				$free[ $field['id'] ] = $field;
			}
		}

		// Propiedades de HubSpot que no han encontrado campo en Elementor.
		// Se devuelve la definición completa para poder crear el campo en Elementor.
		$unmatched  = array();
		$suggested  = array();

		foreach ( $hubspot_fields as $hs ) {
			if ( isset( $taken_props[ $hs['name'] ] ) ) {
				continue;
			}

			$hs['suggestion'] = self::suggest( $hs, $free, $suggested );

			if ( $hs['suggestion'] ) {
				$suggested[ $hs['suggestion']['id'] ] = true;
			}

			$unmatched[] = $hs;
		}

		// Índice de propiedades vigentes en HubSpot.
		$by_name = array();
		foreach ( $hubspot_fields as $hs ) {
			$by_name[ $hs['name'] ] = $hs;
		}

		// Mapeo final (lo conservado + lo recién propuesto).
		$final = array();
		foreach ( $kept as $item ) {
			$final[ $item['id'] ] = $item['property'];
		}
		foreach ( $mapping as $id => $item ) {
			$final[ $id ] = $item['property'];
		}

		$obsolete         = array();
		$required_updates = array();

		foreach ( $elementor_fields as $field ) {
			$id       = $field['id'];
			$property = isset( $final[ $id ] ) ? $final[ $id ] : '';

			if ( '' === $property ) {
				continue;
			}

			// La propiedad mapeada ya no existe en el formulario de HubSpot:
			// HubSpot rechazaría TODO el envío con un 400.
			if ( ! isset( $by_name[ $property ] ) ) {
				$obsolete[] = array(
					'id'       => $id,
					'label'    => isset( $field['label'] ) ? $field['label'] : $id,
					'property' => $property,
				);
				continue;
			}

			// En HubSpot es obligatoria pero en Elementor no.
			if ( ! empty( $by_name[ $property ]['required'] ) && empty( $field['required'] ) ) {
				$required_updates[] = array(
					'id'       => $id,
					'label'    => isset( $field['label'] ) ? $field['label'] : $id,
					'property' => $property,
				);
			}
		}

		return array(
			'mapping'          => $mapping,
			'unmatched'        => $unmatched,
			'kept'             => $kept,
			'obsolete'         => $obsolete,
			'required_updates' => $required_updates,
		);
	}

	/**
	 * Propone (sin aplicarlo) un campo existente para una propiedad sin mapear.
	 *
	 * Evita crear un campo duplicado cuando el formulario ya tiene uno que
	 * probablemente le corresponde, aunque no dé la puntuación para mapearlo solo.
	 *
	 * @param array $hs        Propiedad de HubSpot.
	 * @param array $free      Campos de Elementor libres.
	 * @param array $suggested Campos ya propuestos para otra propiedad.
	 * @return array|null
	 */
	protected static function suggest( array $hs, array $free, array $suggested ) {
		$best = null;

		foreach ( $free as $id => $field ) {
			if ( isset( $suggested[ $id ] ) ) {
				continue;
			}

			$score = self::score( $field, $hs );

			if ( $score >= self::SUGGEST && ( ! $best || $score > $best['score'] ) ) {
				$best = array(
					'id'     => $id,
					'label'  => isset( $field['label'] ) ? $field['label'] : $id,
					'score'  => $score,
					'reason' => 'similar',
				);
			}
		}

		if ( $best ) {
			return $best;
		}

		// Sin parecido en el texto: si solo queda un campo libre del mismo
		// tipo (un único textarea, por ejemplo), se propone por tipo.
		$affinity   = self::type_affinity();
		$compatible = array();

		foreach ( $free as $id => $field ) {
			if ( isset( $suggested[ $id ] ) ) {
				continue;
			}

			$etype = isset( $field['type'] ) ? strtolower( $field['type'] ) : '';
			$htype = isset( $hs['type'] ) ? strtolower( $hs['type'] ) : '';

			if ( $etype && $htype && isset( $affinity[ $etype ] ) && in_array( $htype, $affinity[ $etype ], true ) ) {
				$compatible[ $id ] = $field;
			}
		}

		if ( 1 === count( $compatible ) ) {
			$id    = key( $compatible );
			$field = $compatible[ $id ];

			return array(
				'id'     => $id,
				'label'  => isset( $field['label'] ) ? $field['label'] : $id,
				'score'  => 0,
				'reason' => 'type',
			);
		}

		return null;
	}

	/**
	 * Puntúa (0-100) la afinidad entre un campo de Elementor y una propiedad.
	 *
	 * @param array $field Campo de Elementor: id, label, type.
	 * @param array $hs    Propiedad de HubSpot: name, label, type.
	 * @return int
	 */
	public static function score( array $field, array $hs ) {
		$eid    = self::normalize( isset( $field['id'] ) ? $field['id'] : '' );
		$elabel = self::normalize( isset( $field['label'] ) ? $field['label'] : '' );
		$etype  = isset( $field['type'] ) ? strtolower( (string) $field['type'] ) : '';

		$hname  = self::normalize( $hs['name'] );
		$hlabel = self::normalize( $hs['label'] );
		$htype  = isset( $hs['type'] ) ? strtolower( (string) $hs['type'] ) : '';

		$score = 0;

		if ( $eid && $eid === $hname ) {
			$score = 100;
		} elseif ( $elabel && $hlabel && $elabel === $hlabel ) {
			$score = 96;
		} elseif ( ( $elabel && $elabel === $hname ) || ( $eid && $eid === $hlabel ) ) {
			$score = 94;
		} else {
			$synonyms = self::synonyms();
			$list     = isset( $synonyms[ $hs['name'] ] ) ? $synonyms[ $hs['name'] ] : array();

			// También se comparan los sinónimos contra la etiqueta de HubSpot.
			if ( ! $list ) {
				foreach ( $synonyms as $canonical => $terms ) {
					if ( self::normalize( $canonical ) === $hname || in_array( $hlabel, array_map( array( __CLASS__, 'normalize' ), $terms ), true ) ) {
						$list = $terms;
						break;
					}
				}
			}

			$list = array_map( array( __CLASS__, 'normalize' ), $list );

			if ( $list && ( in_array( $elabel, $list, true ) || in_array( $eid, $list, true ) ) ) {
				$score = 88;
			} else {
				$score = self::fuzzy( $elabel, $hlabel );
				$score = max( $score, self::fuzzy( $eid, $hname ) );
				$score = max( $score, self::fuzzy( $elabel, $hname ) );
			}
		}

		if ( $score <= 0 ) {
			return 0;
		}

		// Ajuste por tipo de campo.
		$affinity = self::type_affinity();
		if ( isset( $affinity[ $etype ] ) ) {
			if ( in_array( $htype, $affinity[ $etype ], true ) ) {
				$score += 6;
			} elseif ( in_array( $etype, array( 'email', 'tel', 'date' ), true ) && $htype && ! in_array( $htype, $affinity[ $etype ], true ) ) {
				$score -= 12; // Un campo email no debería mapear a una propiedad de otro tipo.
			}
		}

		return (int) max( 0, min( 100, round( $score ) ) );
	}

	/**
	 * Similitud entre dos etiquetas comparando PALABRAS, no cadenas sueltas.
	 *
	 * Comparar cadenas concatenadas producía falsos positivos graves
	 * ("Número de invitados" contra "Número de Nit / Tax ID" comparten el
	 * prefijo pero no significan lo mismo), así que se mide el solapamiento
	 * de palabras distintivas.
	 *
	 * @param string $a Texto A.
	 * @param string $b Texto B.
	 * @return int 0-100. Por debajo de THRESHOLD solo sirve como sugerencia.
	 */
	protected static function fuzzy( $a, $b ) {
		$tokens_a = self::tokens( $a );
		$tokens_b = self::tokens( $b );

		if ( ! $tokens_a || ! $tokens_b ) {
			return 0;
		}

		if ( ! array_diff( $tokens_a, $tokens_b ) && ! array_diff( $tokens_b, $tokens_a ) ) {
			return 92;
		}

		$common = array_intersect( $tokens_a, $tokens_b );
		$dice   = ( 2 * count( $common ) ) / ( count( $tokens_a ) + count( $tokens_b ) );

		if ( $dice >= 0.6 ) {
			return (int) round( 60 + ( $dice * 30 ) );
		}

		// Una sola palabra a cada lado: ahí sí tiene sentido comparar cadenas.
		if ( 1 === count( $tokens_a ) && 1 === count( $tokens_b ) ) {
			$one = $tokens_a[0];
			$two = $tokens_b[0];

			if ( strlen( $one ) >= 5 && strlen( $two ) >= 5 && ( false !== strpos( $one, $two ) || false !== strpos( $two, $one ) ) ) {
				return 78;
			}

			similar_text( $one, $two, $percent );

			if ( $percent >= 85 ) {
				return (int) round( 62 + ( ( $percent - 85 ) * 1.2 ) );
			}
		}

		// Solapamiento parcial: no basta para mapear, sí para sugerir.
		return $dice > 0 ? (int) round( $dice * 50 ) : 0;
	}

	/**
	 * Palabras significativas de un texto (sin acentos ni palabras vacías).
	 *
	 * @param string $text Texto.
	 * @return string[]
	 */
	public static function tokens( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
		$text = strtolower( $text );

		$parts = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! $parts ) {
			return array();
		}

		$stopwords = array(
			'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas', 'y', 'o', 'u',
			'tu', 'su', 'mi', 'para', 'por', 'con', 'en', 'al', 'a', 'es', 'lo', 'que',
			'the', 'of', 'your', 'my', 'our', 'and', 'or', 'for', 'to', 'in', 'is', 'it', 'this',
		);

		$out = array();

		foreach ( $parts as $part ) {
			if ( in_array( $part, $stopwords, true ) || strlen( $part ) < 2 ) {
				continue;
			}
			$out[] = $part;
		}

		// Si solo había palabras vacías se conservan tal cual.
		return array_values( array_unique( $out ? $out : $parts ) );
	}

	/**
	 * Normaliza texto para comparar: minúsculas, sin acentos ni separadores.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
		$text = strtolower( $text );
		$text = preg_replace( '/[^a-z0-9]+/', '', $text );

		// Terminaciones habituales que no aportan ("campo", "*", ":").
		return (string) $text;
	}
}
