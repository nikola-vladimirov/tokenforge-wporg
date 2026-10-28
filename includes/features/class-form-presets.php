<?php
/**
 * Form presets for the builder's own form element: whole forms, single
 * fields, a style (underlined, rounded, soft) and the user's own saved
 * presets. Nothing here is a new element: a preset only fills the settings
 * of the form that is being edited.
 *
 * How it hooks in:
 * - bricks/elements/form/control_groups and bricks/elements/form/controls add
 *   a "Presets (TokenForge)" group with a style select (tfStyle) and a hint
 *   control (tfPresetUi) that assets/builder/form-presets.js turns into the
 *   preset pickers (form_presets switch);
 * - bricks/element/render_attributes adds the class form--<style> to the form
 *   (form_styles switch; the CSS comes with the components stylesheet);
 * - REST routes tokenforge/v1/form-presets keep the saved presets in one
 *   option.
 *
 * Placeholders in a built-in preset (resolved by the script on apply, because
 * every field gets a new ID): a field's "ref" names it; a setting that is
 * exactly "@ref" becomes that field's ID, and "{{@ref}}" inside text becomes
 * "{{ID}}". The "ref" key itself is dropped.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Whole-form, field and style presets for the builder's form.
 */
class Form_Presets {

	/** Saved presets (id => name, settings, saved). */
	const OPTION = 'tokenforge_form_presets';

	/** Most saved presets, and the largest one (bytes of JSON). */
	const MAX_PRESETS = 50;
	const MAX_BYTES   = 65536;

	/** Form styles: class suffix => label (see style_labels()). */
	const STYLES = array( 'underline', 'rounded', 'soft' );

	/**
	 * Hooks (each part only while its switch is on).
	 */
	public function __construct() {
		$ui    = self::switch_on( 'form_presets' );
		$style = self::switch_on( 'form_styles' );
		if ( ! $ui && ! $style ) {
			return;
		}
		add_filter( 'bricks/elements/form/control_groups', array( $this, 'control_groups' ) );
		add_filter( 'bricks/elements/form/controls', array( $this, 'controls' ) );
		if ( $style ) {
			add_filter( 'bricks/element/render_attributes', array( $this, 'attributes' ), 10, 3 );
		}
		if ( $ui ) {
			add_action( 'rest_api_init', array( $this, 'routes' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'builder' ), 14 );
		}
	}

	/**
	 * Is a switch on? A missing key counts as on.
	 *
	 * @param string $key Settings key.
	 * @return bool
	 */
	public static function switch_on( $key ) {
		$s = Components::settings();
		return ! isset( $s[ $key ] ) || ! empty( $s[ $key ] );
	}

	/**
	 * The control group.
	 *
	 * @param array $groups Control groups.
	 * @return array
	 */
	public function control_groups( $groups ) {
		$groups['tfPresets'] = array(
			'title' => esc_html__( 'Presets (TokenForge)', 'tokenforge' ),
			'tab'   => 'content',
		);
		return $groups;
	}

	/**
	 * The controls: style select and the hint the script decorates.
	 *
	 * @param array $controls Controls.
	 * @return array
	 */
	public function controls( $controls ) {
		if ( self::switch_on( 'form_presets' ) ) {
			$controls['tfPresetUi'] = array(
				'tab'     => 'content',
				'group'   => 'tfPresets',
				'type'    => 'info',
				'content' => esc_html__( 'Fill this form from a ready-made or saved preset, or add one field. Applying a whole form replaces its fields and actions.', 'tokenforge' ),
			);
		}
		if ( self::switch_on( 'form_styles' ) ) {
			$controls['tfStyle'] = array(
				'tab'         => 'content',
				'group'       => 'tfPresets',
				'label'       => esc_html__( 'Style', 'tokenforge' ),
				'type'        => 'select',
				'options'     => self::style_labels(),
				'placeholder' => esc_html__( 'Default', 'tokenforge' ),
			);
		}
		return $controls;
	}

	/**
	 * Style names for the select.
	 *
	 * @return array
	 */
	public static function style_labels() {
		return array(
			'underline' => esc_html__( 'Underlined fields', 'tokenforge' ),
			'rounded'   => esc_html__( 'Rounded', 'tokenforge' ),
			'soft'      => esc_html__( 'Soft (filled fields)', 'tokenforge' ),
		);
	}

	/**
	 * Add form--<style> to the form's root.
	 *
	 * @param array  $attributes Attributes by key.
	 * @param string $key        Attribute key.
	 * @param object $element    Element.
	 * @return array
	 */
	public function attributes( $attributes, $key, $element ) {
		if ( '_root' !== $key || ! is_object( $element ) || ! isset( $element->name ) || 'form' !== $element->name ) {
			return $attributes;
		}
		$style = is_array( $element->settings ) && isset( $element->settings['tfStyle'] ) ? $element->settings['tfStyle'] : '';
		if ( in_array( $style, self::STYLES, true ) ) {
			$attributes['_root']['class'][] = 'form--' . $style;
		}
		return $attributes;
	}

	/**
	 * In the builder: load the preset pickers.
	 *
	 * @return void
	 */
	public function builder() {
		if ( ! \TokenForge\Platforms\Bricks::is_builder_context() ) {
			return;
		}
		wp_enqueue_style( 'tokenforge-form-presets', TOKENFORGE_URL . 'assets/builder/form-presets.css', array(), TOKENFORGE_VERSION );
		wp_enqueue_script( 'tokenforge-form-presets', TOKENFORGE_URL . 'assets/builder/form-presets.js', array(), TOKENFORGE_VERSION, true );
		wp_localize_script(
			'tokenforge-form-presets',
			'TokenForgeFormPresets',
			array(
				'url'     => esc_url_raw( rest_url( 'tokenforge/v1/form-presets' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'canSave' => current_user_can( 'manage_options' ),
				'forms'   => self::forms(),
				'fields'  => self::field_presets(),
				'saved'   => self::saved_list(),
				'i18n'    => array(
					'form'      => __( 'Whole form', 'tokenforge' ),
					'pickForm'  => __( 'Choose a form preset', 'tokenforge' ),
					'builtIn'   => __( 'Ready-made', 'tokenforge' ),
					'savedGrp'  => __( 'Saved', 'tokenforge' ),
					'apply'     => __( 'Apply', 'tokenforge' ),
					'confirm'   => __( 'Replace this form\'s fields and actions with the preset?', 'tokenforge' ),
					'field'     => __( 'Field', 'tokenforge' ),
					'pickField' => __( 'Choose a field preset', 'tokenforge' ),
					'addField'  => __( 'Add field', 'tokenforge' ),
					'save'      => __( 'Save as preset', 'tokenforge' ),
					'name'      => __( 'Name of the preset', 'tokenforge' ),
					'delete'    => __( 'Delete', 'tokenforge' ),
					'sure'      => __( 'Delete this saved preset?', 'tokenforge' ),
					'export'    => __( 'Export', 'tokenforge' ),
					'copied'    => __( 'Copied to the clipboard.', 'tokenforge' ),
					'copy'      => __( 'Copy this preset:', 'tokenforge' ),
					'import'    => __( 'Import', 'tokenforge' ),
					'paste'     => __( 'Paste the preset (JSON):', 'tokenforge' ),
					'bad'       => __( 'That is not a form preset.', 'tokenforge' ),
					'noForm'    => __( 'Select a form first.', 'tokenforge' ),
					'failed'    => __( 'Could not save. Try again.', 'tokenforge' ),
					'saved'     => __( 'Saved.', 'tokenforge' ),
				),
			)
		);
	}

	/**
	 * A field for a built-in preset. "ref" names it for placeholders.
	 *
	 * @param string $ref  Reference name.
	 * @param string $type Bricks field type.
	 * @param string $label Label (already translated).
	 * @param array  $more Other field keys.
	 * @return array
	 */
	private static function f( $ref, $type, $label, $more = array() ) {
		return array( 'ref' => $ref, 'type' => $type, 'label' => $label ) + $more;
	}

	/**
	 * Single-field presets: key => name, field.
	 *
	 * @return array
	 */
	public static function field_presets() {
		$f = array( __CLASS__, 'f' );
		return array(
			'full-name'  => array( 'name' => __( 'Full name', 'tokenforge' ), 'field' => call_user_func( $f, 'name', 'text', __( 'Full name', 'tokenforge' ), array( 'autocomplete' => 'name', 'required' => true ) ) ),
			'first-name' => array( 'name' => __( 'First name', 'tokenforge' ), 'field' => call_user_func( $f, 'first', 'text', __( 'First name', 'tokenforge' ), array( 'autocomplete' => 'given-name', 'width' => '50', 'required' => true ) ) ),
			'last-name'  => array( 'name' => __( 'Last name', 'tokenforge' ), 'field' => call_user_func( $f, 'last', 'text', __( 'Last name', 'tokenforge' ), array( 'autocomplete' => 'family-name', 'width' => '50', 'required' => true ) ) ),
			'email'      => array( 'name' => __( 'Email', 'tokenforge' ), 'field' => call_user_func( $f, 'email', 'email', __( 'Email', 'tokenforge' ), array( 'autocomplete' => 'email', 'required' => true ) ) ),
			'phone'      => array( 'name' => __( 'Phone', 'tokenforge' ), 'field' => call_user_func( $f, 'phone', 'tel', __( 'Phone', 'tokenforge' ), array( 'autocomplete' => 'tel' ) ) ),
			'phone-mask' => array( 'name' => __( 'Phone with mask', 'tokenforge' ), 'field' => call_user_func( $f, 'phone', 'tel', __( 'Phone', 'tokenforge' ), array( 'autocomplete' => 'tel', 'tfMask' => '+359 ### ### ###' ) ) ),
			'company'    => array( 'name' => __( 'Company', 'tokenforge' ), 'field' => call_user_func( $f, 'company', 'text', __( 'Company', 'tokenforge' ), array( 'autocomplete' => 'organization' ) ) ),
			'message'    => array( 'name' => __( 'Message', 'tokenforge' ), 'field' => call_user_func( $f, 'message', 'textarea', __( 'Message', 'tokenforge' ), array( 'required' => true ) ) ),
			'consent'    => array( 'name' => __( 'Consent', 'tokenforge' ), 'field' => call_user_func( $f, 'consent', 'checkbox', __( 'Consent', 'tokenforge' ), array( 'options' => __( 'I agree to the privacy policy', 'tokenforge' ), 'required' => true ) ) ),
			'date'       => array( 'name' => __( 'Date', 'tokenforge' ), 'field' => call_user_func( $f, 'date', 'datepicker', __( 'Date', 'tokenforge' ), array( 'tfDateMin' => 0 ) ) ),
			'slider'     => array( 'name' => __( 'Number slider', 'tokenforge' ), 'field' => call_user_func( $f, 'amount', 'number', __( 'Amount', 'tokenforge' ), array( 'min' => 0, 'max' => 100, 'step' => 1, 'value' => '50', 'tfLook' => 'slider' ) ) ),
			'rating'     => array( 'name' => __( 'Star rating', 'tokenforge' ), 'field' => call_user_func( $f, 'rating', 'number', __( 'Rating', 'tokenforge' ), array( 'min' => 1, 'max' => 5, 'step' => 1, 'tfLook' => 'rating' ) ) ),
		);
	}

	/**
	 * Whole-form presets: key => name, settings.
	 *
	 * @return array
	 */
	public static function forms() {
		$fp      = self::field_presets();
		$field   = function ( $key, $more = array() ) use ( $fp ) {
			return $more + $fp[ $key ]['field'];
		};
		$mail    = array( 'actions' => array( 'email' ), 'emailTo' => 'admin_email', 'emailContent' => '{{all_fields}}' );
		$service = __( 'Service', 'tokenforge' );
		$qty     = __( 'Quantity', 'tokenforge' );
		$start   = __( 'Start date', 'tokenforge' );
		$end     = __( 'End date', 'tokenforge' );

		return array(
			'contact'    => array(
				'name'     => __( 'Contact form', 'tokenforge' ),
				'settings' => array(
					'fields'       => array( $field( 'full-name' ), $field( 'email' ), $field( 'phone' ), $field( 'message' ), $field( 'consent' ) ),
					'emailSubject' => __( 'Contact form request', 'tokenforge' ),
				) + $mail,
			),
			'quote'      => array(
				'name'     => __( 'Quote calculator', 'tokenforge' ),
				'settings' => array(
					'fields'         => array(
						self::f( 'service', 'select', $service, array( 'options' => "250:" . __( 'Logo design', 'tokenforge' ) . "\n600:" . __( 'Website', 'tokenforge' ) . "\n120:" . __( 'Poster', 'tokenforge' ), 'valueLabelOptions' => true, 'required' => true ) ),
						self::f( 'qty', 'number', $qty, array( 'min' => 1, 'max' => 20, 'step' => 1, 'value' => '1', 'tfLook' => 'slider' ) ),
						self::f( 'total', 'text', __( 'Total', 'tokenforge' ), array( 'tfCalc' => '{' . $service . '} * {' . $qty . '}', 'tfCalcPrefix' => 'EUR ' ) ),
						$field( 'email' ),
					),
					'tfStepsSummary' => true,
					'actions'        => array( 'email', 'save-submission' ),
					'emailTo'        => 'admin_email',
					'emailContent'   => '{{all_fields}}',
					'emailSubject'   => __( 'Quote request', 'tokenforge' ),
				),
			),
			'booking'    => array(
				'name'     => __( 'Booking request', 'tokenforge' ),
				'settings' => array(
					'fields'       => array(
						$field( 'full-name' ),
						$field( 'email' ),
						self::f( 'start', 'datepicker', $start, array( 'tfDateMin' => 1, 'width' => '50', 'required' => true ) ),
						self::f( 'end', 'datepicker', $end, array( 'tfDateAfter' => $start, 'tfDateGap' => 1, 'width' => '50', 'required' => true ) ),
						self::f( 'guests', 'number', __( 'Guests', 'tokenforge' ), array( 'min' => 1, 'max' => 10, 'step' => 1, 'value' => '2', 'tfLook' => 'slider' ) ),
						$field( 'message', array( 'required' => false ) ),
					),
					'actions'      => array( 'email', 'save-submission' ),
					'emailTo'      => 'admin_email',
					'emailContent' => '{{all_fields}}',
					'emailSubject' => __( 'Booking request', 'tokenforge' ),
				),
			),
			'signup'     => array(
				'name'     => __( 'Sign-up', 'tokenforge' ),
				'settings' => array(
					'fields'               => array(
						self::f( 'user', 'text', __( 'Username', 'tokenforge' ), array( 'autocomplete' => 'username', 'required' => true ) ),
						$field( 'email' ),
						self::f( 'pass', 'password', __( 'Password', 'tokenforge' ), array( 'autocomplete' => 'new-password', 'required' => true ) ),
					),
					'actions'              => array( 'registration' ),
					'registrationUserName' => '@user',
					'registrationEmail'    => '@email',
					'registrationPassword' => '@pass',
				),
			),
			'profile'    => array(
				'name'     => __( 'Profile edit', 'tokenforge' ),
				'settings' => array(
					'fields'         => array(
						$field( 'first-name', array( 'value' => '{wp_user_first_name}' ) ),
						$field( 'last-name', array( 'value' => '{wp_user_last_name}' ) ),
						$field( 'email', array( 'value' => '{wp_user_email}' ) ),
					),
					'actions'        => array( 'tf-meta' ),
					'tfMetaTarget'   => 'user',
					'tfMetaRows'     => array(
						array( 'key' => 'first_name', 'value' => '{{@first}}', 'mode' => 'replace', 'skipEmpty' => false ),
						array( 'key' => 'last_name', 'value' => '{{@last}}', 'mode' => 'replace', 'skipEmpty' => false ),
					),
					'successMessage' => __( 'Profile updated.', 'tokenforge' ),
				),
			),
			'newsletter' => array(
				'name'     => __( 'Newsletter', 'tokenforge' ),
				'settings' => array(
					'fields'       => array( $field( 'email' ), $field( 'consent' ) ),
					'emailSubject' => __( 'New newsletter subscriber', 'tokenforge' ),
				) + $mail,
			),
		);
	}

	/* ---------- saved presets ---------- */

	/**
	 * Saved presets as a list for the script.
	 *
	 * @return array
	 */
	public static function saved_list() {
		$all = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $id => $p ) {
			if ( isset( $p['name'], $p['settings'] ) ) {
				$out[] = array( 'id' => (string) $id, 'name' => (string) $p['name'], 'settings' => $p['settings'] );
			}
		}
		return $out;
	}

	/**
	 * REST routes.
	 *
	 * @return void
	 */
	public function routes() {
		$ns   = 'tokenforge/v1';
		$edit = function () {
			return current_user_can( 'edit_posts' );
		};
		$manage = function () {
			return current_user_can( 'manage_options' ); // a preset can carry HTML fields
		};
		register_rest_route(
			$ns,
			'/form-presets',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $edit,
					'callback'            => function () {
						return rest_ensure_response( self::saved_list() );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $manage,
					'callback'            => array( $this, 'save' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/form-presets/(?P<id>[a-z0-9]+)',
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $manage,
				'callback'            => array( $this, 'delete' ),
			)
		);
	}

	/**
	 * Save a preset.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( $req ) {
		$name     = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$raw      = $req->get_param( 'settings' );
		$settings = self::sanitize_settings( is_array( $raw ) ? $raw : array() );
		if ( '' === $name || empty( $settings['fields'] ) ) {
			return new \WP_Error( 'tokenforge_bad_preset', __( 'A preset needs a name and fields.', 'tokenforge' ), array( 'status' => 400 ) );
		}
		if ( strlen( wp_json_encode( $settings ) ) > self::MAX_BYTES ) {
			return new \WP_Error( 'tokenforge_big_preset', __( 'That preset is too large.', 'tokenforge' ), array( 'status' => 413 ) );
		}
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( count( $all ) >= self::MAX_PRESETS ) {
			return new \WP_Error( 'tokenforge_many_presets', __( 'Too many saved presets. Delete one first.', 'tokenforge' ), array( 'status' => 400 ) );
		}
		$id         = strtolower( wp_generate_password( 8, false ) );
		$all[ $id ] = array( 'name' => $name, 'settings' => $settings, 'saved' => time() );
		update_option( self::OPTION, $all, false );
		return rest_ensure_response( self::saved_list() );
	}

	/**
	 * Delete a preset.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( $req ) {
		$all = get_option( self::OPTION, array() );
		$id  = (string) $req->get_param( 'id' );
		if ( ! is_array( $all ) || ! isset( $all[ $id ] ) ) {
			return new \WP_Error( 'tokenforge_no_preset', __( 'Preset not found.', 'tokenforge' ), array( 'status' => 404 ) );
		}
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		return rest_ensure_response( self::saved_list() );
	}

	/**
	 * One scalar: numbers and booleans kept; strings kept as typed for users who
	 * may post any HTML (kses would turn "{Qty} < 5" into "{Qty} "), else
	 * through wp_kses_post.
	 *
	 * @param mixed $v Value.
	 * @return array [ ok, value ]
	 */
	private static function scalar( $v ) {
		if ( is_string( $v ) ) {
			return array( true, current_user_can( 'unfiltered_html' ) ? $v : wp_kses_post( $v ) );
		}
		return is_bool( $v ) || is_int( $v ) || is_float( $v ) ? array( true, $v ) : array( false, null );
	}

	/**
	 * A row (field or repeater row): keys of letters, digits, underscore; scalar values.
	 *
	 * @param mixed $row Row.
	 * @return array
	 */
	private static function row( $row ) {
		$out = array();
		foreach ( is_array( $row ) ? $row : array() as $k => $v ) {
			if ( is_string( $k ) && preg_match( '/^[A-Za-z0-9_]+$/', $k ) ) {
				list( $ok, $val ) = self::scalar( $v );
				if ( $ok ) {
					$out[ $k ] = $val;
				}
			}
		}
		return $out;
	}

	/**
	 * Keep only what a preset may carry (public static so it can be tested alone).
	 *
	 * @param array $in Raw settings.
	 * @return array
	 */
	public static function sanitize_settings( $in ) {
		$out = array();
		foreach ( is_array( $in ) ? $in : array() as $key => $val ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( 'fields' === $key ) {
				$out['fields'] = array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $val ) ? $val : array() ) ) );
			} elseif ( 'actions' === $key ) {
				$out['actions'] = array_values( array_filter( array_map( 'sanitize_key', array_filter( is_array( $val ) ? $val : array(), 'is_string' ) ) ) );
			} elseif ( preg_match( '/^(tf[A-Z]|email|success|redirect|confirmation|registration)[A-Za-z0-9_]*$/', $key ) ) {
				list( $ok, $one ) = self::scalar( $val );
				if ( $ok ) {
					$out[ $key ] = $one;
				} elseif ( is_array( $val ) ) {
					// A list of scalars, or of rows (a repeater such as tfMetaRows).
					$list = array();
					foreach ( $val as $item ) {
						if ( is_array( $item ) ) {
							$list[] = self::row( $item );
						} else {
							list( $iok, $iv ) = self::scalar( $item );
							if ( $iok ) {
								$list[] = $iv;
							}
						}
					}
					$out[ $key ] = $list;
				}
			}
		}
		return $out;
	}
}
