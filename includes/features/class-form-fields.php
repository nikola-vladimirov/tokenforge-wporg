<?php
/**
 * More for the fields of the builder's own form: input looks (slider, range,
 * star rating, input mask, character counter), date rules, error messages per
 * rule, choice options filled from site content, and a dynamic tag that shows
 * a field's value anywhere on the page as the visitor types.
 *
 * Every field keeps its own builder type, so the value travels in the
 * builder's own input: email, webhook, saved submission and create post all
 * get it unchanged. Each part has its own switch (Components settings
 * form_fields, form_dates, form_messages, form_options, form_live).
 *
 * Hooks (documented builder filters): bricks/elements/form/controls,
 * bricks/element/render_attributes, bricks/element/settings,
 * bricks/form/validate, bricks/dynamic_tags_list,
 * bricks/dynamic_data/render_tag, bricks/dynamic_data/render_content.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Field looks, date rules, messages, options from content, live values.
 */
class Form_Fields {

	/** Highest number of options filled from content. */
	const MAX_OPTIONS = 500;

	/**
	 * Parts switched on: looks, dates, messages, options, live => bool.
	 *
	 * @var array
	 */
	private $on = array();

	/**
	 * Hooks for the parts that are switched on.
	 */
	public function __construct() {
		$this->on = self::parts();
		if ( ! array_filter( $this->on ) ) {
			return;
		}
		add_filter( 'bricks/elements/form/controls', array( $this, 'controls' ), 11 );
		add_filter( 'bricks/element/render_attributes', array( $this, 'attributes' ), 10, 3 );
		if ( $this->on['options'] ) {
			add_filter( 'bricks/element/settings', array( $this, 'fill_options' ), 10, 2 );
		}
		if ( $this->on['dates'] ) {
			add_filter( 'bricks/form/validate', array( $this, 'validate' ), 11, 2 );
		}
		if ( $this->on['live'] ) {
			add_filter( 'bricks/dynamic_tags_list', array( $this, 'tags' ) );
			add_filter( 'bricks/dynamic_data/render_tag', array( $this, 'render_tag' ), 10, 3 );
			add_filter( 'bricks/dynamic_data/render_content', array( $this, 'render_content' ), 10, 3 );
		}
	}

	/**
	 * Which parts are on.
	 *
	 * @return array
	 */
	public static function parts() {
		$s = Components::settings();
		return array(
			'looks'    => ! empty( $s['form_fields'] ),
			'dates'    => ! empty( $s['form_dates'] ),
			'messages' => ! empty( $s['form_messages'] ),
			'options'  => ! empty( $s['form_options'] ),
			'live'     => ! empty( $s['form_live'] ),
		);
	}

	// ------------------------------------------------------------------
	// Builder settings
	// ------------------------------------------------------------------

	/**
	 * The field settings of the parts that are on.
	 *
	 * @param array $controls Controls.
	 * @return array
	 */
	public function controls( $controls ) {
		if ( ! isset( $controls['fields']['fields'] ) || ! is_array( $controls['fields']['fields'] ) ) {
			return $controls;
		}
		$add = array();
		if ( $this->on['looks'] ) {
			$add += array(
				'tfLookSep'     => array(
					'type'  => 'separator',
					'label' => esc_html__( 'Input look (TokenForge)', 'tokenforge' ),
				),
				'tfLook'        => array(
					'label'       => esc_html__( 'Show as', 'tokenforge' ),
					'type'        => 'select',
					'options'     => array(
						'slider' => esc_html__( 'Slider (Number field)', 'tokenforge' ),
						'rating' => esc_html__( 'Star rating (Number field)', 'tokenforge' ),
						'range'  => esc_html__( 'Range with two handles (Text field)', 'tokenforge' ),
					),
					'placeholder' => esc_html__( 'Default', 'tokenforge' ),
					'description' => esc_html__( 'Slider and stars use the Number field\'s Min, Max and Step (stars: Max is the number of stars, 5 when empty). A range sends "from - to", for example "100 - 500".', 'tokenforge' ),
					'required'    => array( 'type', '=', array( 'number', 'text' ) ),
				),
				'tfRangeMin'    => array(
					'label'    => esc_html__( 'Range: lowest', 'tokenforge' ),
					'type'     => 'number',
					'placeholder' => '0',
					'required' => array( array( 'type', '=', 'text' ), array( 'tfLook', '=', 'range' ) ),
				),
				'tfRangeMax'    => array(
					'label'    => esc_html__( 'Range: highest', 'tokenforge' ),
					'type'     => 'number',
					'placeholder' => '100',
					'required' => array( array( 'type', '=', 'text' ), array( 'tfLook', '=', 'range' ) ),
				),
				'tfRangeStep'   => array(
					'label'    => esc_html__( 'Range: step', 'tokenforge' ),
					'type'     => 'number',
					'placeholder' => '1',
					'required' => array( array( 'type', '=', 'text' ), array( 'tfLook', '=', 'range' ) ),
				),
				'tfLookPrefix'  => array(
					'label'       => esc_html__( 'Before the value', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => '€',
					'required'    => array( 'tfLook', '=', array( 'slider', 'range' ) ),
				),
				'tfLookSuffix'  => array(
					'label'       => esc_html__( 'After the value', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => ' m²',
					'required'    => array( 'tfLook', '=', array( 'slider', 'range' ) ),
				),
				'tfMask'        => array(
					'label'       => esc_html__( 'Input mask', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => '+359 ### ### ###',
					'description' => esc_html__( '# = a digit, @ = a letter, * = a letter or digit; anything else is typed for the visitor. The field only counts as filled in when the mask is complete.', 'tokenforge' ),
					'required'    => array( 'type', '=', array( 'text', 'tel' ) ),
				),
				'tfCounter'     => array(
					'label'       => esc_html__( 'Character counter', 'tokenforge' ),
					'type'        => 'checkbox',
					'description' => esc_html__( 'Shows how many characters are typed, and the limit when the field has a max length.', 'tokenforge' ),
					'required'    => array( 'type', '=', array( 'text', 'textarea' ) ),
				),
			);
		}
		if ( $this->on['dates'] ) {
			$add += array(
				'tfDateSep'      => array(
					'type'     => 'separator',
					'label'    => esc_html__( 'Date rules (TokenForge)', 'tokenforge' ),
					'required' => array( 'type', '=', 'datepicker' ),
				),
				'tfDateMin'      => array(
					'label'       => esc_html__( 'Earliest: days from today', 'tokenforge' ),
					'type'        => 'number',
					'min'         => 0,
					'placeholder' => esc_html__( 'Any date', 'tokenforge' ),
					'description' => esc_html__( '0 blocks past dates; 2 starts the day after tomorrow.', 'tokenforge' ),
					'required'    => array( 'type', '=', 'datepicker' ),
				),
				'tfDateMax'      => array(
					'label'       => esc_html__( 'Latest: days from today', 'tokenforge' ),
					'type'        => 'number',
					'min'         => 0,
					'placeholder' => esc_html__( 'Any date', 'tokenforge' ),
					'required'    => array( 'type', '=', 'datepicker' ),
				),
				'tfDateWeekdays' => array(
					'label'       => esc_html__( 'Not on these weekdays', 'tokenforge' ),
					'type'        => 'select',
					'multiple'    => true,
					'options'     => self::weekday_options(),
					'placeholder' => esc_html__( 'None', 'tokenforge' ),
					'required'    => array( 'type', '=', 'datepicker' ),
				),
				'tfDateOff'      => array(
					'label'       => esc_html__( 'Closed dates (one per line)', 'tokenforge' ),
					'type'        => 'textarea',
					'placeholder' => "2026-12-24\n2026-12-31 to 2027-01-02",
					'description' => esc_html__( 'A date as year-month-day, or a span with "to".', 'tokenforge' ),
					'required'    => array( 'type', '=', 'datepicker' ),
				),
				'tfDateAfter'    => array(
					'label'       => esc_html__( 'Not before the date in field', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'Label or ID of the start date', 'tokenforge' ),
					'description' => esc_html__( 'For an end date: it can never be before the start date the visitor picked.', 'tokenforge' ),
					'required'    => array( 'type', '=', 'datepicker' ),
				),
				'tfDateGap'      => array(
					'label'       => esc_html__( 'At least this many days after it', 'tokenforge' ),
					'type'        => 'number',
					'min'         => 0,
					'placeholder' => '0',
					'required'    => array( array( 'type', '=', 'datepicker' ), array( 'tfDateAfter', '!=', '' ) ),
				),
			);
		}
		if ( $this->on['messages'] ) {
			$no_msg = array( 'hidden', 'html', 'rememberme' );
			$add   += array(
				'tfMsgSep'      => array(
					'type'     => 'separator',
					'label'    => esc_html__( 'Error messages (TokenForge)', 'tokenforge' ),
					'required' => array( 'type', '!=', $no_msg ),
				),
				'tfMsgRequired' => array(
					'label'       => esc_html__( 'When left empty', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'Please fill in your phone number.', 'tokenforge' ),
					'required'    => array( 'type', '!=', $no_msg ),
				),
				'tfMsgInvalid'  => array(
					'label'       => esc_html__( 'When filled in wrongly', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'Please check the number, e.g. +359 888 123 456.', 'tokenforge' ),
					'description' => esc_html__( 'Wrong email or web address, pattern or mask not matched, a number out of range, a date that is not allowed. These replace the Error message above for their case.', 'tokenforge' ),
					'required'    => array( 'type', '!=', $no_msg ),
				),
			);
		}
		if ( $this->on['options'] ) {
			$choice = array( 'select', 'radio', 'checkbox' );
			$add   += array(
				'tfOptSep'      => array(
					'type'     => 'separator',
					'label'    => esc_html__( 'Options from content (TokenForge)', 'tokenforge' ),
					'required' => array( 'type', '=', $choice ),
				),
				'tfOptFrom'     => array(
					'label'       => esc_html__( 'Fill the options from', 'tokenforge' ),
					'type'        => 'select',
					'options'     => array(
						'posts' => esc_html__( 'Posts or pages', 'tokenforge' ),
						'terms' => esc_html__( 'Categories, tags or other terms', 'tokenforge' ),
						'users' => esc_html__( 'Users', 'tokenforge' ),
						'field' => esc_html__( 'The choices of a custom field (ACF, Meta Box)', 'tokenforge' ),
					),
					'placeholder' => esc_html__( 'The options typed above', 'tokenforge' ),
					'description' => esc_html__( 'Filled when the page is shown, so new content shows up by itself. The builder canvas shows the typed options.', 'tokenforge' ),
					'required'    => array( 'type', '=', $choice ),
				),
				'tfOptPostType' => array(
					'label'       => esc_html__( 'Post type', 'tokenforge' ),
					'type'        => 'select',
					'options'     => self::post_type_options(),
					'placeholder' => esc_html__( 'Posts', 'tokenforge' ),
					'required'    => array( array( 'type', '=', $choice ), array( 'tfOptFrom', '=', 'posts' ) ),
				),
				'tfOptTax'      => array(
					'label'       => esc_html__( 'Taxonomy', 'tokenforge' ),
					'type'        => 'select',
					'options'     => self::taxonomy_options(),
					'placeholder' => esc_html__( 'Categories', 'tokenforge' ),
					'required'    => array( array( 'type', '=', $choice ), array( 'tfOptFrom', '=', 'terms' ) ),
				),
				'tfOptRole'     => array(
					'label'       => esc_html__( 'Role', 'tokenforge' ),
					'type'        => 'select',
					'options'     => self::role_options(),
					'placeholder' => esc_html__( 'Any role but administrators', 'tokenforge' ),
					'required'    => array( array( 'type', '=', $choice ), array( 'tfOptFrom', '=', 'users' ) ),
				),
				'tfOptField'    => array(
					'label'       => esc_html__( 'Field name or key', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => 'service_type',
					'required'    => array( array( 'type', '=', $choice ), array( 'tfOptFrom', '=', 'field' ) ),
				),
				'tfOptValue'    => array(
					'label'       => esc_html__( 'Value sent', 'tokenforge' ),
					'type'        => 'select',
					'options'     => array(
						'title' => esc_html__( 'The name shown', 'tokenforge' ),
						'id'    => esc_html__( 'The ID', 'tokenforge' ),
						'slug'  => esc_html__( 'The slug (user: the public profile slug)', 'tokenforge' ),
					),
					'placeholder' => esc_html__( 'The name shown', 'tokenforge' ),
					'description' => esc_html__( 'The name reads best in emails; an ID suits Create post and Update meta.', 'tokenforge' ),
					'required'    => array( array( 'type', '=', $choice ), array( 'tfOptFrom', '!=', array( '', 'field' ) ) ),
				),
				'tfOptLimit'    => array(
					'label'       => esc_html__( 'At most', 'tokenforge' ),
					'type'        => 'number',
					'min'         => 1,
					'max'         => self::MAX_OPTIONS,
					'placeholder' => '100',
					'required'    => array( array( 'type', '=', $choice ), array( 'tfOptFrom', '!=', array( '', 'field' ) ) ),
				),
				'tfOptKeep'     => array(
					'label'       => esc_html__( 'Keep the typed options first', 'tokenforge' ),
					'type'        => 'checkbox',
					'description' => esc_html__( 'For a first choice such as "Not sure yet".', 'tokenforge' ),
					'required'    => array( array( 'type', '=', $choice ), array( 'tfOptFrom', '!=', '' ) ),
				),
			);
		}
		$controls['fields']['fields'] += $add;
		return $controls;
	}

	/**
	 * Weekdays as the browser numbers them (0 = Sunday), Monday first.
	 *
	 * @return array
	 */
	private static function weekday_options() {
		global $wp_locale;
		$out = array();
		foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $d ) {
			$out[ (string) $d ] = $wp_locale ? $wp_locale->get_weekday( $d ) : (string) $d;
		}
		return $out;
	}

	/**
	 * Public post types.
	 *
	 * @return array
	 */
	private static function post_type_options() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $type ) {
			if ( 'attachment' !== $name ) {
				$out[ $name ] = $type->labels->name;
			}
		}
		return $out;
	}

	/**
	 * Public taxonomies.
	 *
	 * @return array
	 */
	private static function taxonomy_options() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $name => $tax ) {
			$out[ $name ] = $tax->labels->name;
		}
		return $out;
	}

	/**
	 * User roles.
	 *
	 * @return array
	 */
	private static function role_options() {
		$roles = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
		return array_map( 'translate_user_role', $roles );
	}

	// ------------------------------------------------------------------
	// Front end
	// ------------------------------------------------------------------

	/**
	 * Data attributes on the form's fields (and the script when a field needs it).
	 *
	 * @param array  $attributes All attributes of the element, by key.
	 * @param string $key        The key being printed.
	 * @param object $element    Element.
	 * @return array
	 */
	public function attributes( $attributes, $key, $element ) {
		if ( ! is_object( $element ) || ! isset( $element->name ) || 'form' !== $element->name ) {
			return $attributes;
		}
		$settings = is_array( $element->settings ) ? $element->settings : array();
		$by_index = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? $settings['fields'] : array();
		if ( '_root' === $key ) {
			foreach ( $by_index as $field ) {
				if ( is_array( $field ) && $this->uses_script( $field ) ) {
					$attributes['_root']['data-tf-form'] = '1';
					Form_Extras::enqueue();
					break;
				}
			}
			return $attributes;
		}
		if ( ! preg_match( '/^field(-wrapper)?-(\d+)$/', $key, $m ) || ! isset( $by_index[ (int) $m[2] ] ) || ! is_array( $by_index[ (int) $m[2] ] ) ) {
			return $attributes;
		}
		$field = $by_index[ (int) $m[2] ];
		$type  = isset( $field['type'] ) ? $field['type'] : 'text';
		$attr  = isset( $attributes[ $key ] ) && is_array( $attributes[ $key ] ) ? $attributes[ $key ] : array();
		if ( '' !== $m[1] ) {
			// The wrapper: a checkbox group shows its message from here.
			if ( $this->on['messages'] && 'checkbox' === $type && self::msg( $field, 'tfMsgRequired' ) ) {
				$attr['data-error-message'] = self::msg( $field, 'tfMsgRequired' );
			}
			$attributes[ $key ] = $attr;
			return $attributes;
		}
		$look = $this->look( $field );
		if ( $look ) {
			$attr['data-tf-look'] = wp_json_encode( $look );
			if ( 'slider' === $look['t'] ) {
				$attr['type'] = 'range'; // a native slider; min, max and step stay the builder's
				if ( ! isset( $field['min'] ) ) {
					$attr['min'] = '0';
				}
				if ( ! isset( $field['max'] ) ) {
					$attr['max'] = '100';
				}
			}
		}
		if ( $this->on['looks'] && in_array( $type, array( 'text', 'tel' ), true ) && self::mask( $field ) ) {
			$mask                 = self::mask( $field );
			$attr['data-tf-mask'] = $mask;
			if ( empty( $field['pattern'] ) ) {
				$attr['pattern'] = self::mask_pattern( $mask ); // the browser and the builder check the mask is complete
			}
			if ( ! preg_match( '/[@*]/', $mask ) ) {
				$attr['inputmode'] = 'numeric';
			}
			if ( empty( $field['placeholder'] ) ) {
				$attr['placeholder'] = preg_replace( '/[#@*]/', '_', $mask );
			}
		}
		if ( $this->on['looks'] && ! empty( $field['tfCounter'] ) && in_array( $type, array( 'text', 'textarea' ), true ) ) {
			$attr['data-tf-counter'] = '1';
		}
		if ( $this->on['dates'] && 'datepicker' === $type ) {
			$rules = $this->date_rules( $field, array_values( $by_index ) );
			if ( $rules ) {
				$attr['data-tf-date'] = wp_json_encode( $rules );
			}
		}
		if ( $this->on['messages'] ) {
			$req = self::msg( $field, 'tfMsgRequired' );
			$bad = self::msg( $field, 'tfMsgInvalid' );
			if ( '' !== $req || '' !== $bad ) {
				$attr['data-tf-msg'] = wp_json_encode(
					array(
						'r' => $req,
						'i' => $bad,
					)
				);
				// The builder's script only watches inputs that have a message.
				if ( empty( $attr['data-error-message'] ) ) {
					$attr['data-error-message'] = '' !== $req ? $req : $bad;
				}
			}
		}
		$attributes[ $key ] = $attr;
		return $attributes;
	}

	/**
	 * Does a field need the front-end script?
	 *
	 * @param array $field Field settings.
	 * @return bool
	 */
	private function uses_script( $field ) {
		$type = isset( $field['type'] ) ? $field['type'] : 'text';
		return (bool) $this->look( $field )
			|| ( $this->on['looks'] && ( self::mask( $field ) || ! empty( $field['tfCounter'] ) ) )
			|| ( $this->on['dates'] && 'datepicker' === $type && self::has_date_rules( $field ) )
			|| ( $this->on['messages'] && ( '' !== self::msg( $field, 'tfMsgRequired' ) || '' !== self::msg( $field, 'tfMsgInvalid' ) ) );
	}

	/**
	 * A field's look for the browser: { t: slider|rating|range, … } or null.
	 *
	 * @param array $field Field settings.
	 * @return array|null
	 */
	private function look( $field ) {
		if ( ! $this->on['looks'] || empty( $field['tfLook'] ) ) {
			return null;
		}
		$type = isset( $field['type'] ) ? $field['type'] : 'text';
		$t    = $field['tfLook'];
		$pre  = isset( $field['tfLookPrefix'] ) ? wp_kses( (string) $field['tfLookPrefix'], array() ) : ''; // spaces kept, like the calculation's
		$suf  = isset( $field['tfLookSuffix'] ) ? wp_kses( (string) $field['tfLookSuffix'], array() ) : '';
		if ( 'number' === $type && 'slider' === $t ) {
			return array(
				't' => 'slider',
				'p' => $pre,
				's' => $suf,
			);
		}
		if ( 'number' === $type && 'rating' === $t ) {
			$max = isset( $field['max'] ) && is_numeric( $field['max'] ) ? (int) $field['max'] : 5;
			return array(
				't'   => 'rating',
				'max' => max( 1, min( 10, $max ) ),
				/* translators: %s: number of stars */
				'one' => __( '%s star', 'tokenforge' ),
				/* translators: %s: number of stars */
				'n'   => __( '%s stars', 'tokenforge' ),
			);
		}
		if ( 'text' === $type && 'range' === $t ) {
			$min  = isset( $field['tfRangeMin'] ) && is_numeric( $field['tfRangeMin'] ) ? (float) $field['tfRangeMin'] : 0;
			$max  = isset( $field['tfRangeMax'] ) && is_numeric( $field['tfRangeMax'] ) ? (float) $field['tfRangeMax'] : 100;
			$step = isset( $field['tfRangeStep'] ) && is_numeric( $field['tfRangeStep'] ) && $field['tfRangeStep'] > 0 ? (float) $field['tfRangeStep'] : 1;
			return array(
				't'    => 'range',
				'min'  => min( $min, $max ),
				'max'  => max( $min, $max ),
				'step' => $step,
				'p'    => $pre,
				's'    => $suf,
				'from' => __( 'From', 'tokenforge' ),
				'to'   => __( 'To', 'tokenforge' ),
			);
		}
		return null;
	}

	/**
	 * A field's input mask ('' when none).
	 *
	 * @param array $field Field settings.
	 * @return string
	 */
	private static function mask( $field ) {
		return isset( $field['tfMask'] ) ? trim( wp_strip_all_tags( (string) $field['tfMask'] ) ) : '';
	}

	/**
	 * The pattern a complete mask matches ("+359 ###" becomes "\+359 \d\d\d").
	 *
	 * @param string $mask Mask.
	 * @return string
	 */
	public static function mask_pattern( $mask ) {
		$out = '';
		foreach ( preg_split( '//u', $mask, -1, PREG_SPLIT_NO_EMPTY ) as $c ) {
			if ( '#' === $c ) {
				$out .= '\d';
			} elseif ( '@' === $c ) {
				$out .= '[A-Za-zÀ-ɏͰ-ϿЀ-ӿ]';
			} elseif ( '*' === $c ) {
				$out .= '[A-Za-z0-9À-ɏͰ-ϿЀ-ӿ]';
			} else {
				// Only syntax characters are escaped: browsers compile patterns with the "v" flag.
				$out .= false !== strpos( '^$\\.*+?()[]{}|/', $c ) ? '\\' . $c : $c;
			}
		}
		return $out;
	}

	/**
	 * A message setting ('' when not set).
	 *
	 * @param array  $field Field settings.
	 * @param string $key   tfMsgRequired or tfMsgInvalid.
	 * @return string
	 */
	private static function msg( $field, $key ) {
		return isset( $field[ $key ] ) ? trim( wp_strip_all_tags( (string) $field[ $key ] ) ) : '';
	}

	// ------------------------------------------------------------------
	// Dates
	// ------------------------------------------------------------------

	/**
	 * Has a date field rules?
	 *
	 * @param array $field Field settings.
	 * @return bool
	 */
	private static function has_date_rules( $field ) {
		foreach ( array( 'tfDateMin', 'tfDateMax', 'tfDateOff', 'tfDateAfter' ) as $k ) {
			if ( isset( $field[ $k ] ) && '' !== trim( (string) $field[ $k ] ) ) {
				return true;
			}
		}
		return ! empty( $field['tfDateWeekdays'] );
	}

	/**
	 * A date field's rules for the browser: min/max days from today, weekdays
	 * off, closed spans [from, to] as Y-m-d, the start date's input name, the gap.
	 *
	 * @param array $field  Field settings.
	 * @param array $fields All fields.
	 * @return array Empty when there are none.
	 */
	private function date_rules( $field, $fields ) {
		if ( ! self::has_date_rules( $field ) ) {
			return array();
		}
		$r = array();
		foreach ( array( 'tfDateMin' => 'min', 'tfDateMax' => 'max' ) as $k => $out ) {
			if ( isset( $field[ $k ] ) && is_numeric( $field[ $k ] ) ) {
				$r[ $out ] = max( 0, (int) $field[ $k ] );
			}
		}
		$wd = self::weekdays_off( $field );
		if ( $wd ) {
			$r['wd'] = $wd;
		}
		$off = self::closed_spans( isset( $field['tfDateOff'] ) ? $field['tfDateOff'] : '' );
		if ( $off ) {
			$r['off'] = $off;
		}
		$start = self::start_field( $field, $fields );
		if ( $start ) {
			$r['after'] = Form_Extras::input_name( $start );
			$r['gap']   = isset( $field['tfDateGap'] ) && is_numeric( $field['tfDateGap'] ) ? max( 0, (int) $field['tfDateGap'] ) : 0;
		}
		return $r;
	}

	/**
	 * Weekdays a date may not fall on (0 = Sunday).
	 *
	 * @param array $field Field settings.
	 * @return int[]
	 */
	private static function weekdays_off( $field ) {
		$wd = isset( $field['tfDateWeekdays'] ) ? (array) $field['tfDateWeekdays'] : array();
		$wd = array_values( array_unique( array_filter( array_map( 'intval', array_filter( $wd, 'is_numeric' ) ), function ( $d ) {
			return $d >= 0 && $d <= 6;
		} ) ) );
		sort( $wd );
		return $wd;
	}

	/**
	 * Closed dates: lines of "2026-12-24" or "2026-12-31 to 2027-01-02".
	 *
	 * @param string $text Lines.
	 * @return array [ [ from, to ], … ] as Y-m-d.
	 */
	public static function closed_spans( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n|,/', (string) $text ) as $line ) {
			if ( preg_match( '/^\s*(\d{4}-\d{2}-\d{2})(?:\s*(?:to|-|–)\s*(\d{4}-\d{2}-\d{2}))?\s*$/u', $line, $m ) ) {
				$a     = $m[1];
				$b     = isset( $m[2] ) && '' !== $m[2] ? $m[2] : $a;
				$out[] = $a <= $b ? array( $a, $b ) : array( $b, $a );
			}
		}
		return $out;
	}

	/**
	 * The start date field an end date follows.
	 *
	 * @param array $field  Field settings.
	 * @param array $fields All fields.
	 * @return array|null
	 */
	private static function start_field( $field, $fields ) {
		if ( empty( $field['tfDateAfter'] ) ) {
			return null;
		}
		$start = Form_Extras::find_field( $fields, (string) $field['tfDateAfter'] );
		return $start && isset( $start['id'], $field['id'] ) && $start['id'] !== $field['id'] && 'datepicker' === ( isset( $start['type'] ) ? $start['type'] : '' ) ? $start : null;
	}

	/**
	 * Read a date as the builder's date picker writes it (the site's date format).
	 *
	 * @param string $value Submitted text.
	 * @param array  $field Field settings.
	 * @return \DateTimeImmutable|null Midnight of that day, site time zone.
	 */
	public static function read_date( $value, $field ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$format = get_option( 'date_format' ) . ( isset( $field['time'] ) ? ' H:i' : '' );
		$value  = self::english_names( $value );
		// ponytail: a date the site's format and language cannot read is let through (the browser still blocked it); a hidden ISO copy from the script if that matters.
		$d      = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
		if ( ! $d ) {
			$t = strtotime( $value );
			$d = false !== $t ? ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( wp_timezone() ) : null;
		}
		return $d ? $d->setTime( 0, 0 ) : null;
	}

	/**
	 * Month and weekday names of the site language as English, so PHP can read
	 * "16 октомври 2026" with the format "j F Y".
	 *
	 * @param string $value Date text.
	 * @return string
	 */
	private static function english_names( $value ) {
		global $wp_locale;
		if ( ! $wp_locale || 0 === strpos( get_locale(), 'en' ) ) {
			return $value;
		}
		$map = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$local = $wp_locale->get_month( $m );
			$time  = gmmktime( 0, 0, 0, $m, 1, 2026 );
			$map[ $local ]                                   = gmdate( 'F', $time );
			$map[ $wp_locale->get_month_abbrev( $local ) ] = gmdate( 'M', $time );
		}
		for ( $d = 0; $d <= 6; $d++ ) {
			$local = $wp_locale->get_weekday( $d );
			$time  = gmmktime( 0, 0, 0, 1, 4 + $d, 2026 ); // 4 January 2026 is a Sunday
			$map[ $local ]                                     = gmdate( 'l', $time );
			$map[ $wp_locale->get_weekday_abbrev( $local ) ] = gmdate( 'D', $time );
		}
		return strtr( $value, $map ); // strtr tries longer names first
	}

	/**
	 * Is a day allowed by a date field's own rules (not the start date link)?
	 *
	 * @param \DateTimeImmutable $day   The day (midnight).
	 * @param array              $field Field settings.
	 * @param \DateTimeImmutable $today Today (midnight, site time zone).
	 * @return bool
	 */
	public static function day_allowed( $day, $field, $today ) {
		$diff = (int) $today->diff( $day )->format( '%r%a' );
		if ( isset( $field['tfDateMin'] ) && is_numeric( $field['tfDateMin'] ) && $diff < max( 0, (int) $field['tfDateMin'] ) ) {
			return false;
		}
		if ( isset( $field['tfDateMax'] ) && is_numeric( $field['tfDateMax'] ) && $diff > max( 0, (int) $field['tfDateMax'] ) ) {
			return false;
		}
		if ( in_array( (int) $day->format( 'w' ), self::weekdays_off( $field ), true ) ) {
			return false;
		}
		$ymd = $day->format( 'Y-m-d' );
		foreach ( self::closed_spans( isset( $field['tfDateOff'] ) ? $field['tfDateOff'] : '' ) as $span ) {
			if ( $ymd >= $span[0] && $ymd <= $span[1] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * On submit: dates outside their rules are refused (the browser blocks
	 * them too, this catches a hand-made request).
	 *
	 * @param array  $errors Validation errors so far.
	 * @param object $form   The builder's form handler.
	 * @return array
	 */
	public function validate( $errors, $form ) {
		if ( ! is_object( $form ) || ! method_exists( $form, 'get_settings' ) || ! method_exists( $form, 'get_fields' ) ) {
			return $errors;
		}
		$settings  = $form->get_settings();
		$fields    = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? array_values( $settings['fields'] ) : array();
		$submitted = (array) $form->get_fields();
		$today     = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 );
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || 'datepicker' !== ( isset( $field['type'] ) ? $field['type'] : '' ) || empty( $field['id'] ) || ! self::has_date_rules( $field ) ) {
				continue;
			}
			$days = self::submitted_days( $submitted, $field );
			if ( ! $days ) {
				continue;
			}
			$ok = true;
			foreach ( $days as $day ) {
				$ok = $ok && self::day_allowed( $day, $field, $today );
			}
			$start = self::start_field( $field, $fields );
			if ( $ok && $start ) {
				$from = self::submitted_days( $submitted, $start );
				if ( $from ) {
					$gap = isset( $field['tfDateGap'] ) && is_numeric( $field['tfDateGap'] ) ? max( 0, (int) $field['tfDateGap'] ) : 0;
					$ok  = (int) end( $from )->diff( $days[0] )->format( '%r%a' ) >= $gap;
				}
			}
			if ( ! $ok ) {
				$msg      = self::msg( $field, 'tfMsgInvalid' );
				$label    = isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : __( 'the date', 'tokenforge' );
				/* translators: %s: field label */
				$errors[] = '' !== $msg ? $msg : sprintf( __( 'Please choose another date for %s.', 'tokenforge' ), $label );
			}
		}
		return $errors;
	}

	/**
	 * The days a date field sent (two for a range "a to b").
	 *
	 * @param array $submitted Submitted values.
	 * @param array $field     Field settings.
	 * @return \DateTimeImmutable[]
	 */
	private static function submitted_days( $submitted, $field ) {
		$raw = '';
		foreach ( array( Form_Extras::input_name( $field ), 'form-field-' . $field['id'] ) as $name ) {
			if ( isset( $submitted[ $name ] ) && is_scalar( $submitted[ $name ] ) ) {
				$raw = (string) $submitted[ $name ];
				break;
			}
		}
		$out = array();
		// Only " to " (a range) splits: date formats such as "F j, Y" have commas.
		foreach ( preg_split( '/\s+to\s+/', $raw ) as $part ) {
			$d = self::read_date( $part, $field );
			if ( $d ) {
				$out[] = $d;
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Options from content
	// ------------------------------------------------------------------

	/**
	 * Fill choice options from content when a form is shown.
	 *
	 * @param array  $settings Element settings.
	 * @param object $element  Element.
	 * @return array
	 */
	public function fill_options( $settings, $element ) {
		if ( ! is_object( $element ) || ! isset( $element->name ) || 'form' !== $element->name || empty( $settings['fields'] ) || ! is_array( $settings['fields'] ) ) {
			return $settings;
		}
		foreach ( $settings['fields'] as $i => $field ) {
			if ( ! is_array( $field ) || empty( $field['tfOptFrom'] ) || ! in_array( isset( $field['type'] ) ? $field['type'] : '', array( 'select', 'radio', 'checkbox' ), true ) ) {
				continue;
			}
			$pairs = self::content_options( $field );
			if ( null === $pairs ) {
				continue;
			}
			$lines = array();
			$typed = ! empty( $field['tfOptKeep'] ) && ! empty( $field['options'] ) ? preg_split( '/\r\n|\r|\n/', trim( (string) $field['options'] ) ) : array();
			$keyed = ! empty( $field['valueLabelOptions'] );
			foreach ( $typed as $line ) {
				if ( '' === trim( $line ) ) {
					continue;
				}
				// Typed lines join value:label lines: a plain one is its own value (no colon in a value).
				$lines[] = $keyed ? trim( $line ) : self::option_value( $line ) . ':' . trim( $line );
			}
			foreach ( $pairs as $value => $label ) {
				// No braces: the builder runs dynamic data over options, and titles or names are not trusted.
				$lines[] = self::option_value( (string) $value ) . ':' . trim( str_replace( array( "\r", "\n", '{', '}' ), ' ', (string) $label ) );
			}
			$settings['fields'][ $i ]['options']           = implode( "\n", $lines );
			$settings['fields'][ $i ]['valueLabelOptions'] = true;
		}
		return $settings;
	}

	/**
	 * An option's value as a filled field sends it: one line, no colon, no braces.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function option_value( $value ) {
		return trim( str_replace( array( "\r", "\n", ':', '{', '}' ), ' ', (string) $value ) );
	}

	/**
	 * A field's options from content: value => label (null when the source is
	 * unknown). Kept for the request: a form renders and validates more than once.
	 *
	 * @param array $field Field settings.
	 * @return array|null
	 */
	public static function content_options( $field ) {
		static $memo = array();
		$key = md5( wp_json_encode( array_intersect_key( $field, array_flip( array( 'tfOptFrom', 'tfOptPostType', 'tfOptTax', 'tfOptRole', 'tfOptField', 'tfOptValue', 'tfOptLimit' ) ) ) ) );
		if ( ! array_key_exists( $key, $memo ) ) {
			$memo[ $key ] = self::query_options( $field );
		}
		return $memo[ $key ];
	}

	/**
	 * Query a field's options from content.
	 *
	 * @param array $field Field settings.
	 * @return array|null
	 */
	private static function query_options( $field ) {
		$limit = isset( $field['tfOptLimit'] ) && is_numeric( $field['tfOptLimit'] ) ? max( 1, min( self::MAX_OPTIONS, (int) $field['tfOptLimit'] ) ) : 100;
		$by    = isset( $field['tfOptValue'] ) && in_array( $field['tfOptValue'], array( 'id', 'slug' ), true ) ? $field['tfOptValue'] : 'title';
		$out   = array();
		switch ( $field['tfOptFrom'] ) {
			case 'posts':
				$type = isset( $field['tfOptPostType'] ) && post_type_exists( $field['tfOptPostType'] ) && is_post_type_viewable( $field['tfOptPostType'] ) ? $field['tfOptPostType'] : 'post';
				$q    = get_posts(
					array(
						'post_type'        => $type,
						'post_status'      => 'publish',
						'posts_per_page'   => $limit,
						'orderby'          => 'title',
						'order'            => 'ASC',
						'has_password'     => false,
						'no_found_rows'    => true,
						'suppress_filters' => false,
					)
				);
				foreach ( $q as $p ) {
					$title = wp_strip_all_tags( get_the_title( $p ) );
					$value = 'id' === $by ? $p->ID : ( 'slug' === $by ? $p->post_name : $title );
					$out[ (string) $value ] = $title;
				}
				return $out;
			case 'terms':
				$tax   = isset( $field['tfOptTax'] ) && taxonomy_exists( $field['tfOptTax'] ) && is_taxonomy_viewable( $field['tfOptTax'] ) ? $field['tfOptTax'] : 'category';
				$terms = get_terms(
					array(
						'taxonomy'   => $tax,
						'hide_empty' => false,
						'number'     => $limit,
						'orderby'    => 'name',
					)
				);
				if ( is_wp_error( $terms ) ) {
					return array();
				}
				foreach ( $terms as $t ) {
					$value = 'id' === $by ? $t->term_id : ( 'slug' === $by ? $t->slug : $t->name );
					$out[ (string) $value ] = wp_strip_all_tags( $t->name );
				}
				return $out;
			case 'users':
				$args = array(
					'number'  => $limit,
					'orderby' => 'display_name',
					'fields'  => array( 'ID', 'display_name', 'user_nicename' ),
				);
				if ( ! empty( $field['tfOptRole'] ) && is_string( $field['tfOptRole'] ) ) {
					$args['role'] = sanitize_key( $field['tfOptRole'] );
				} else {
					$args['role__not_in'] = array( 'administrator' ); // a public list never shows the site's admins unless picked
				}
				foreach ( get_users( $args ) as $u ) {
					$value = 'id' === $by ? $u->ID : ( 'slug' === $by ? $u->user_nicename : $u->display_name );
					$out[ (string) $value ] = wp_strip_all_tags( $u->display_name );
				}
				return $out;
			case 'field':
				return self::field_choices( isset( $field['tfOptField'] ) ? trim( (string) $field['tfOptField'] ) : '' );
		}
		return null;
	}

	/**
	 * The choices of an ACF or Meta Box field.
	 *
	 * @param string $name Field name or key.
	 * @return array value => label
	 */
	private static function field_choices( $name ) {
		if ( '' === $name ) {
			return array();
		}
		if ( function_exists( 'acf_get_field' ) ) {
			$f = acf_get_field( $name );
			if ( is_array( $f ) && ! empty( $f['choices'] ) && is_array( $f['choices'] ) ) {
				return array_map( 'wp_strip_all_tags', $f['choices'] );
			}
		}
		if ( function_exists( 'rwmb_get_field_settings' ) ) {
			$f = rwmb_get_field_settings( $name, array(), get_the_ID() );
			if ( is_array( $f ) && ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
				return array_map( 'wp_strip_all_tags', $f['options'] );
			}
		}
		return array();
	}

	// ------------------------------------------------------------------
	// Live values: {tf_form_value:Field}
	// ------------------------------------------------------------------

	/**
	 * The tag in the builder's dynamic data list.
	 *
	 * @param array $tags Tags.
	 * @return array
	 */
	public function tags( $tags ) {
		$tags[] = array(
			'name'  => '{tf_form_value:Field label}',
			'label' => __( 'Form field value, live (put the field\'s label or ID after the colon)', 'tokenforge' ),
			'group' => 'TokenForge',
		);
		return $tags;
	}

	/**
	 * One tag on its own (a field set to a single tag).
	 *
	 * @param mixed  $tag     The tag without braces.
	 * @param mixed  $post    Post.
	 * @param string $context text, link, image, …
	 * @return mixed
	 */
	public function render_tag( $tag, $post, $context = 'text' ) {
		if ( ! is_string( $tag ) || 0 !== strpos( $tag, 'tf_form_value:' ) ) {
			return $tag;
		}
		return self::live( substr( $tag, 14 ), $context );
	}

	/**
	 * Tags inside text.
	 *
	 * @param string $content Content.
	 * @param mixed  $post    Post.
	 * @param string $context Context.
	 * @return string
	 */
	public function render_content( $content, $post = null, $context = 'text' ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{tf_form_value:' ) ) {
			return $content;
		}
		return preg_replace_callback(
			'/\{tf_form_value:([^{}]+)\}/',
			function ( $m ) use ( $context ) {
				return self::live( $m[1], $context );
			},
			$content
		);
	}

	/**
	 * The live value element.
	 *
	 * @param string $ref     Field label or ID.
	 * @param string $context Context.
	 * @return string
	 */
	private static function live( $ref, $context ) {
		$ref = trim( wp_strip_all_tags( (string) $ref ) );
		if ( '' === $ref || ! in_array( $context, array( 'text', 'html', '' ), true ) ) {
			return '';
		}
		Form_Extras::enqueue();
		return '<span class="tf-live" data-tf-live="' . esc_attr( $ref ) . '"></span>';
	}
}
