<?php
/**
 * Form extras for the builder's own form element: steps (a form in several
 * parts with a progress bar, Back and Next), conditional fields (a field
 * shows only when another field has a certain value), conditional steps (a
 * step is skipped unless a field has a certain value), a summary step (every
 * answer with Edit links before sending) and saved answers (kept in the
 * visitor's own browser until the form is sent).
 *
 * How it hooks in (all documented builder filters):
 * - bricks/elements/form/control_groups and bricks/elements/form/controls add
 *   the settings: per field "New step starts here" and "Show only when", per
 *   form the step buttons and the progress style;
 * - bricks/element/render_attributes puts those settings on the form and on
 *   each field wrapper as data attributes, and loads a small script;
 * - bricks/form/validate: on submit, fields hidden by their condition, and the
 *   fields of a skipped step, are not required (the browser does not send them).
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Steps and conditional fields for the builder's form.
 */
class Form_Extras {

	/** Script handle. */
	const HANDLE = 'tokenforge-form-extras';

	/** How a condition compares the other field's value. */
	const OPS = array( 'is', 'isnot', 'contains', 'empty', 'notempty', 'gt', 'lt', 'gte', 'lte' );

	/**
	 * Hooks (only while the setting is on).
	 */
	public function __construct() {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'bricks/elements/form/control_groups', array( $this, 'control_groups' ) );
		add_filter( 'bricks/elements/form/controls', array( $this, 'controls' ) );
		add_filter( 'bricks/element/render_attributes', array( $this, 'attributes' ), 10, 3 );
		add_filter( 'bricks/form/validate', array( $this, 'validate' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'builder' ), 14 );
	}

	/**
	 * In the builder: pick fields for conditions and calculations from lists.
	 *
	 * @return void
	 */
	public function builder() {
		if ( ! \TokenForge\Platforms\Bricks::is_builder_context() ) {
			return;
		}
		wp_enqueue_style( 'tokenforge-form-picker', TOKENFORGE_URL . 'assets/builder/form-picker.css', array(), TOKENFORGE_VERSION );
		wp_enqueue_script( 'tokenforge-form-picker', TOKENFORGE_URL . 'assets/builder/form-picker.js', array(), TOKENFORGE_VERSION, true );
		wp_localize_script(
			'tokenforge-form-picker',
			'TokenForgeFormPicker',
			array(
				'pick'     => __( 'Choose a field', 'tokenforge' ),
				'field'    => __( 'Field', 'tokenforge' ),
				'compare'  => __( 'Compare', 'tokenforge' ),
				'value'    => __( 'Value', 'tokenforge' ),
				'add'      => __( 'Add', 'tokenforge' ),
				'insert'   => __( 'Insert a field', 'tokenforge' ),
				'is'       => __( 'is', 'tokenforge' ),
				'isnot'    => __( 'is not', 'tokenforge' ),
				'contains' => __( 'contains', 'tokenforge' ),
				'empty'    => __( 'is empty', 'tokenforge' ),
				'notempty' => __( 'is not empty', 'tokenforge' ),
			)
		);
	}

	/**
	 * Is the feature on? (Forms, "Steps and conditions in the builder's form".)
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = Components::settings();
		return ! empty( $s['form_extras'] );
	}

	/**
	 * Register the front-end script (enqueued only by a form that uses it).
	 *
	 * @return void
	 */
	public function register() {
		wp_register_script( self::HANDLE, TOKENFORGE_URL . 'assets/front/form-extras.js', array(), TOKENFORGE_VERSION, true );
	}

	/**
	 * Load the front-end script (also for the other form features, which can be
	 * on while steps and conditions are off).
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_register_script( self::HANDLE, TOKENFORGE_URL . 'assets/front/form-extras.js', array(), TOKENFORGE_VERSION, true );
		}
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * A control group for the form-wide step settings.
	 *
	 * @param array $groups Control groups.
	 * @return array
	 */
	public function control_groups( $groups ) {
		$groups['tfSteps'] = array(
			'title' => esc_html__( 'Steps and answers (TokenForge)', 'tokenforge' ),
			'tab'   => 'content',
		);
		return $groups;
	}

	/**
	 * The settings: per field (steps, condition) and per form (step buttons, progress).
	 *
	 * @param array $controls Controls.
	 * @return array
	 */
	public function controls( $controls ) {
		if ( isset( $controls['fields']['fields'] ) && is_array( $controls['fields']['fields'] ) ) {
			$controls['fields']['fields'] += array(
				'tfSeparator'   => array(
					'type'  => 'separator',
					'label' => esc_html__( 'Steps and conditions (TokenForge)', 'tokenforge' ),
				),
				'tfStep'        => array(
					'label'       => esc_html__( 'New step starts here', 'tokenforge' ),
					'type'        => 'checkbox',
					'description' => esc_html__( 'Splits the form into steps with Back and Next buttons. This field is the first of its step.', 'tokenforge' ),
				),
				'tfStepTitle'   => array(
					'label'       => esc_html__( 'Step title', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'Step 2', 'tokenforge' ),
					'required'    => array( 'tfStep', '=', true ),
				),
				'tfStepShowField' => array(
					'label'       => esc_html__( 'Show this step only when field', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'Label or ID of a field in an earlier step', 'tokenforge' ),
					'description' => esc_html__( 'Otherwise the step is skipped: its fields are not sent and never required.', 'tokenforge' ),
					'required'    => array( 'tfStep', '=', true ),
				),
				'tfStepShowOp'    => array(
					'label'       => esc_html__( 'Step condition', 'tokenforge' ),
					'type'        => 'select',
					'options'     => self::op_options(),
					'placeholder' => esc_html__( 'is', 'tokenforge' ),
					'required'    => array(
						array( 'tfStep', '=', true ),
						array( 'tfStepShowField', '!=', '' ),
					),
				),
				'tfStepShowValue' => array(
					'label'       => esc_html__( 'Step value', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'For a checkbox or choice: the option value', 'tokenforge' ),
					'required'    => array(
						array( 'tfStep', '=', true ),
						array( 'tfStepShowField', '!=', '' ),
						array( 'tfStepShowOp', '!=', array( 'empty', 'notempty' ) ),
					),
				),
				'tfStepShowRules' => array(
					'label'       => esc_html__( 'More step conditions (one per line)', 'tokenforge' ),
					'type'        => 'textarea',
					'placeholder' => "{Budget} > 1000\n{Service} = Web design",
					'description' => esc_html__( 'Each line: a field in braces, then = != > < >= <= contains, empty or not empty, then the value.', 'tokenforge' ),
					'required'    => array( 'tfStep', '=', true ),
				),
				'tfStepShowMatch' => array(
					'label'       => esc_html__( 'Show this step when', 'tokenforge' ),
					'type'        => 'select',
					'options'     => self::match_options(),
					'placeholder' => esc_html__( 'All conditions hold', 'tokenforge' ),
					'required'    => array(
						array( 'tfStep', '=', true ),
						array( 'tfStepShowRules', '!=', '' ),
					),
				),
				'tfShowField'   => array(
					'label'       => esc_html__( 'Show only when field', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'Label or ID of another field', 'tokenforge' ),
					'description' => esc_html__( 'Hidden fields are not sent and are never required.', 'tokenforge' ),
				),
				'tfShowOp'      => array(
					'label'       => esc_html__( 'Condition', 'tokenforge' ),
					'type'        => 'select',
					'options'     => self::op_options(),
					'placeholder' => esc_html__( 'is', 'tokenforge' ),
					'required'    => array( 'tfShowField', '!=', '' ),
				),
				'tfShowValue'   => array(
					'label'       => esc_html__( 'Value', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'For a checkbox or choice: the option value', 'tokenforge' ),
					'required'    => array(
						array( 'tfShowField', '!=', '' ),
						array( 'tfShowOp', '!=', array( 'empty', 'notempty' ) ),
					),
				),
				'tfShowRules'   => array(
					'label'       => esc_html__( 'More conditions (one per line)', 'tokenforge' ),
					'type'        => 'textarea',
					'placeholder' => "{Budget} > 1000\n{Topic} = Web design\n{Phone} not empty",
					'description' => esc_html__( 'Each line: a field in braces (its label or ID), then = != > < >= <= contains, empty or not empty, then the value. They count together with "Show only when field" above.', 'tokenforge' ),
				),
				'tfShowMatch'   => array(
					'label'       => esc_html__( 'Show when', 'tokenforge' ),
					'type'        => 'select',
					'options'     => self::match_options(),
					'placeholder' => esc_html__( 'All conditions hold', 'tokenforge' ),
					'required'    => array( 'tfShowRules', '!=', '' ),
				),
				'tfRequireRules' => array(
					'label'       => esc_html__( 'Required only when (one per line)', 'tokenforge' ),
					'type'        => 'textarea',
					'placeholder' => "{Contact by} = Phone",
					'description' => esc_html__( 'The field must be filled in only while these conditions hold; the Required switch above is then not used. The same lines as the conditions above. Checked again on the server when the form is sent.', 'tokenforge' ),
				),
				'tfRequireMatch' => array(
					'label'       => esc_html__( 'Required when', 'tokenforge' ),
					'type'        => 'select',
					'options'     => self::match_options(),
					'placeholder' => esc_html__( 'All conditions hold', 'tokenforge' ),
					'required'    => array( 'tfRequireRules', '!=', '' ),
				),
				'tfCalcSep'     => array(
					'type'  => 'separator',
					'label' => esc_html__( 'Calculation (TokenForge)', 'tokenforge' ),
				),
				'tfCalc'        => array(
					'label'       => esc_html__( 'Calculate', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => '{Quantity} * {Price} + 25',
					'description' => esc_html__( 'Makes this field a result worked out from other fields while the visitor fills in the form (read only; worked out again on the server when the form is sent). Fields in braces by label or ID; numbers; + - * / % ^ and brackets; round(x, 2), ceil(), floor(), min(), max(), abs(), if(test, then, else) with > < >= <= = !=. A choice counts its value (tick "Set options as value:label" and write 250:Logo design); ticked checkboxes add up; hidden fields count as 0. Use a Number, Text or Hidden field.', 'tokenforge' ),
				),
				'tfCalcDecimals' => array(
					'label'       => esc_html__( 'Decimals', 'tokenforge' ),
					'type'        => 'number',
					'min'         => 0,
					'max'         => 6,
					'placeholder' => '2',
					'required'    => array( 'tfCalc', '!=', '' ),
				),
				'tfCalcPrefix'  => array(
					'label'       => esc_html__( 'Before the number', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => '€ ',
					'description' => esc_html__( 'Text fields only (a Number field holds just the number).', 'tokenforge' ),
					'required'    => array( 'tfCalc', '!=', '' ),
				),
				'tfCalcSuffix'  => array(
					'label'       => esc_html__( 'After the number', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => ' / month',
					'required'    => array( 'tfCalc', '!=', '' ),
				),
				'tfCalcFormat'  => array(
					'label'       => esc_html__( 'Number format', 'tokenforge' ),
					'type'        => 'select',
					'options'     => self::format_options(),
					'placeholder' => '1234.50',
					'description' => esc_html__( 'Thousands and decimal separators. Text fields only.', 'tokenforge' ),
					'required'    => array( 'tfCalc', '!=', '' ),
				),
				'tfCalcShow'    => array(
					'label'       => esc_html__( 'Show the result', 'tokenforge' ),
					'type'        => 'select',
					'options'     => array(
						'field' => esc_html__( 'In the field (read only)', 'tokenforge' ),
						'text'  => esc_html__( 'As plain text', 'tokenforge' ),
					),
					'placeholder' => esc_html__( 'In the field (read only)', 'tokenforge' ),
					'description' => esc_html__( 'As plain text: the label and the result, no box. The result is still sent with the form.', 'tokenforge' ),
					'required'    => array( 'tfCalc', '!=', '' ),
				),
			);
		}
		$controls['tfStepsProgress'] = array(
			'group'       => 'tfSteps',
			'label'       => esc_html__( 'Progress', 'tokenforge' ),
			'type'        => 'select',
			'options'     => array(
				'steps' => esc_html__( 'Numbered steps with titles', 'tokenforge' ),
				'bar'   => esc_html__( 'Progress bar', 'tokenforge' ),
				'count' => esc_html__( 'Step 2 of 3', 'tokenforge' ),
				'none'  => esc_html__( 'None', 'tokenforge' ),
			),
			'placeholder' => esc_html__( 'Numbered steps with titles', 'tokenforge' ),
			'description' => esc_html__( 'Tick "New step starts here" on a field to split the form into steps.', 'tokenforge' ),
		);
		$controls['tfStepsNext']     = array(
			'group'       => 'tfSteps',
			'label'       => esc_html__( 'Next button', 'tokenforge' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'Next', 'tokenforge' ),
		);
		$controls['tfStepsBack']     = array(
			'group'       => 'tfSteps',
			'label'       => esc_html__( 'Back button', 'tokenforge' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'Back', 'tokenforge' ),
		);
		$controls['tfStepsFirst']    = array(
			'group'       => 'tfSteps',
			'label'       => esc_html__( 'First step title', 'tokenforge' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'Step 1', 'tokenforge' ),
		);
		$controls['tfStepsSummary']      = array(
			'group'       => 'tfSteps',
			'label'       => esc_html__( 'Summary before sending', 'tokenforge' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Adds a last step that lists every answer, each with an Edit link, above the send button. Works without other steps too.', 'tokenforge' ),
		);
		$controls['tfStepsSummaryTitle'] = array(
			'group'       => 'tfSteps',
			'label'       => esc_html__( 'Summary step title', 'tokenforge' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'Check your answers', 'tokenforge' ),
			'required'    => array( 'tfStepsSummary', '=', true ),
		);
		$controls['tfSaveProgress']      = array(
			'group'       => 'tfSteps',
			'label'       => esc_html__( 'Remember answers on this device', 'tokenforge' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Keeps what a visitor types in their own browser until the form is sent, so a reload or a later visit carries on where they left off. Passwords, files and hidden fields are never kept. Anyone using the same browser can see the kept answers, so leave this off for forms with private details.', 'tokenforge' ),
		);
		return $controls;
	}

	/**
	 * Data attributes on the form and its field wrappers (and the script when needed).
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
		$by_index = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? $settings['fields'] : array(); // keys as the form renders them
		$fields   = array_values( $by_index );
		if ( '_root' === $key ) {
			$steps   = self::step_count( $fields );
			$conds   = (bool) array_filter( $fields, array( __CLASS__, 'has_condition' ) ) || (bool) array_filter( $fields, array( __CLASS__, 'has_calc' ) ) || (bool) array_filter( $fields, array( __CLASS__, 'has_require' ) );
			$summary = ! empty( $settings['tfStepsSummary'] );
			$save    = ! empty( $settings['tfSaveProgress'] );
			if ( $steps > 1 || $summary ) {
				$attributes['_root']['data-tf-steps'] = wp_json_encode(
					array(
						'progress' => self::choice( $settings, 'tfStepsProgress', array( 'steps', 'bar', 'count', 'none' ), 'steps' ),
						'next'     => self::text( $settings, 'tfStepsNext', __( 'Next', 'tokenforge' ) ),
						'back'     => self::text( $settings, 'tfStepsBack', __( 'Back', 'tokenforge' ) ),
						'first'    => self::text( $settings, 'tfStepsFirst', __( 'Step 1', 'tokenforge' ) ),
						/* translators: 1: current step, 2: number of steps */
						'of'       => __( 'Step %1$s of %2$s', 'tokenforge' ),
						'summary'  => $summary,
						'sumTitle' => self::text( $settings, 'tfStepsSummaryTitle', __( 'Check your answers', 'tokenforge' ) ),
						'edit'     => __( 'Edit', 'tokenforge' ),
						'none'     => __( 'Not filled in', 'tokenforge' ),
					)
				);
			}
			if ( $save ) {
				$attributes['_root']['data-tf-save'] = wp_json_encode(
					array(
						'restored' => __( 'Your answers from before are filled in.', 'tokenforge' ),
						'clear'    => __( 'Start over', 'tokenforge' ),
					)
				);
			}
			if ( $steps > 1 || $conds || $summary || $save ) {
				$attributes['_root']['data-tf-form'] = '1';
				self::enqueue();
			}
			return $attributes;
		}
		if ( ! preg_match( '/^field-wrapper-(\d+)$/', $key, $m ) || ! isset( $by_index[ (int) $m[1] ] ) || ! is_array( $by_index[ (int) $m[1] ] ) ) {
			return $attributes;
		}
		$field = $by_index[ (int) $m[1] ];
		$first = array_key_first( $by_index );
		if ( ! empty( $field['tfStep'] ) && (int) $m[1] !== (int) $first ) {
			$title = isset( $field['tfStepTitle'] ) ? trim( wp_strip_all_tags( (string) $field['tfStepTitle'] ) ) : '';
			$attributes[ $key ]['data-tf-step'] = '' !== $title ? $title : '';
			if ( self::has_step_condition( $field ) ) {
				$attributes[ $key ]['data-tf-step-show'] = wp_json_encode( self::browser_rules( $fields, self::field_rules( $field, true ), self::match( $field, 'tfStepShow' ) ) );
			}
		}
		if ( self::has_condition( $field ) ) {
			$attributes[ $key ]['data-tf-show'] = wp_json_encode( self::browser_rules( $fields, self::field_rules( $field, false ), self::match( $field, 'tfShow' ) ) );
		}
		if ( self::has_require( $field ) ) {
			$req         = self::browser_rules( $fields, self::parse_rules( $field['tfRequireRules'] ), self::match( $field, 'tfRequire' ) );
			$req['star'] = ! empty( $settings['requiredAsterisk'] ); // the label's asterisk follows
			$attributes[ $key ]['data-tf-require'] = wp_json_encode( $req );
		}
		if ( self::has_calc( $field ) ) {
			list( $expr, $refs ) = self::calc_parts( $fields, (string) $field['tfCalc'] );
			$attributes[ $key ]['data-tf-calc'] = wp_json_encode(
				array(
					'e' => $expr,
					'r' => array_map(
						function ( $f ) {
							return $f ? self::input_name( $f ) : '';
						},
						$refs
					),
					'd' => self::calc_decimals( $field ),
					'p' => isset( $field['tfCalcPrefix'] ) ? wp_kses( (string) $field['tfCalcPrefix'], array() ) : '',
					's' => isset( $field['tfCalcSuffix'] ) ? wp_kses( (string) $field['tfCalcSuffix'], array() ) : '',
					'f' => self::calc_format( $field ),
					't' => isset( $field['tfCalcShow'] ) && 'text' === $field['tfCalcShow'] ? 1 : 0,
				)
			);
		}
		return $attributes;
	}

	/**
	 * Conditions for the browser: { match, rules: [ { name, op, v } ] }
	 * (rules naming a field that does not exist are left out).
	 *
	 * @param array  $fields Field settings.
	 * @param array  $rules  field_rules() or parse_rules().
	 * @param string $match  all|any.
	 * @return array
	 */
	public static function browser_rules( $fields, $rules, $match ) {
		$out = array();
		foreach ( $rules as $r ) {
			$target = self::find_field( $fields, $r['ref'] );
			if ( $target ) {
				$out[] = array(
					'name' => self::input_name( $target ),
					'op'   => $r['op'],
					'v'    => $r['v'],
				);
			}
		}
		return array(
			'match' => $match,
			'rules' => $out,
		);
	}

	/**
	 * Does a field have "Required only when" lines?
	 *
	 * @param mixed $field Field settings.
	 * @return bool
	 */
	public static function has_require( $field ) {
		return is_array( $field ) && isset( $field['tfRequireRules'] ) && array() !== self::parse_rules( $field['tfRequireRules'] );
	}


	/**
	 * Which fields with "Required only when" must be filled in for a submission:
	 * id => true|false. A hidden field is never required.
	 *
	 * @param array    $fields    Field settings.
	 * @param array    $submitted Submitted values.
	 * @param string[] $hidden    Ids of hidden fields.
	 * @return array
	 */
	public static function required_now( $fields, $submitted, $hidden ) {
		$fields    = array_values( $fields );
		$values_of = self::reader( $fields, $submitted, $hidden );
		$out       = array();
		foreach ( $fields as $field ) {
			if ( ! empty( $field['id'] ) && self::has_require( $field ) ) {
				$out[ $field['id'] ] = ! in_array( $field['id'], $hidden, true ) && self::rules_hold( self::parse_rules( $field['tfRequireRules'] ), self::match( $field, 'tfRequire' ), $values_of );
			}
		}
		return $out;
	}

	/**
	 * Reads a field's submitted values by label or ID; a hidden field reads as empty.
	 *
	 * @param array    $fields    Field settings (a list).
	 * @param array    $submitted Submitted values.
	 * @param string[] $hidden    Ids of hidden fields (by reference: the caller may update it).
	 * @return callable ref => string[]
	 */
	private static function reader( $fields, $submitted, &$hidden ) {
		return function ( $ref ) use ( $fields, $submitted, &$hidden ) {
			$target = self::find_field( $fields, (string) $ref );
			return $target && ! in_array( $target['id'], $hidden, true ) ? self::submitted( $submitted, $target ) : array();
		};
	}

	/**
	 * A field's (or step's) conditions: the one in "Show only when field" and the
	 * lines of "More conditions".
	 *
	 * @param array $field Field settings.
	 * @param bool  $step  The step's conditions (else the field's).
	 * @return array [ [ 'ref' => field label or ID, 'op' => operator, 'v' => value ], … ]
	 */
	public static function field_rules( $field, $step = false ) {
		if ( ! is_array( $field ) || ( $step && empty( $field['tfStep'] ) ) ) {
			return array();
		}
		$k   = $step ? 'tfStepShow' : 'tfShow';
		$out = array();
		if ( isset( $field[ $k . 'Field' ] ) && '' !== trim( (string) $field[ $k . 'Field' ] ) ) {
			$out[] = array(
				'ref' => (string) $field[ $k . 'Field' ],
				'op'  => self::op( $field, $k . 'Op' ),
				'v'   => isset( $field[ $k . 'Value' ] ) ? (string) $field[ $k . 'Value' ] : '',
			);
		}
		return array_merge( $out, self::parse_rules( isset( $field[ $k . 'Rules' ] ) ? $field[ $k . 'Rules' ] : '' ) );
	}

	/**
	 * Condition lines: "{Field} op value", one per line.
	 *
	 * @param string $text Lines.
	 * @return array [ [ 'ref', 'op', 'v' ], … ]
	 */
	public static function parse_rules( $text ) {
		$ops = array(
			'is not empty' => 'notempty',
			'not empty'    => 'notempty',
			'is empty'     => 'empty',
			'empty'        => 'empty',
			'is not'       => 'isnot',
			'!='           => 'isnot',
			'>='           => 'gte',
			'<='           => 'lte',
			'=='           => 'is',
			'='            => 'is',
			'>'            => 'gt',
			'<'            => 'lt',
			'is'           => 'is',
			'contains'     => 'contains',
		);
		$alt = implode( '|', array_map( 'preg_quote', array_keys( $ops ) ) );
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( preg_match( '/^\s*\{([^{}]+)\}\s*(' . $alt . ')\s*(.*?)\s*$/i', wp_strip_all_tags( $line ), $m ) ) {
				$out[] = array(
					'ref' => trim( $m[1] ),
					'op'  => $ops[ strtolower( $m[2] ) ],
					'v'   => trim( $m[3], " \t\"'" ),
				);
			}
		}
		return $out;
	}

	/**
	 * Do condition lines hold for a submission? Fields hidden by their own
	 * conditions read as empty, like in the browser.
	 *
	 * @param array  $fields    Field settings.
	 * @param array  $submitted Submitted values.
	 * @param string $text      Condition lines.
	 * @param string $match     all|any.
	 * @return bool
	 */
	public static function lines_hold( $fields, $submitted, $text, $match ) {
		$fields = array_values( $fields );
		$hidden = self::hidden_fields( $fields, $submitted );
		return self::rules_hold( self::parse_rules( $text ), 'any' === $match ? 'any' : 'all', self::reader( $fields, $submitted, $hidden ) );
	}

	/**
	 * "all" or "any" of a field's (or step's) conditions.
	 *
	 * @param array  $field  Field settings.
	 * @param string $prefix tfShow, tfStepShow or tfRequire.
	 * @return string
	 */
	private static function match( $field, $prefix ) {
		$k = $prefix . 'Match';
		return isset( $field[ $k ] ) && 'any' === $field[ $k ] ? 'any' : 'all';
	}

	/**
	 * Choices for "Show when".
	 *
	 * @return array
	 */
	private static function match_options() {
		return array(
			'all' => esc_html__( 'All conditions hold', 'tokenforge' ),
			'any' => esc_html__( 'Any condition holds', 'tokenforge' ),
		);
	}

	/**
	 * Do a field's conditions hold, given how to read the fields they name?
	 *
	 * @param array    $rules     field_rules().
	 * @param string   $match     all|any.
	 * @param callable $values_of ref → submitted values.
	 * @return bool
	 */
	private static function rules_hold( $rules, $match, $values_of ) {
		if ( ! $rules ) {
			return true;
		}
		foreach ( $rules as $r ) {
			$ok = self::test( $r['op'], $r['v'], $values_of( $r['ref'] ) );
			if ( 'any' === $match && $ok ) {
				return true;
			}
			if ( 'any' !== $match && ! $ok ) {
				return false;
			}
		}
		return 'any' !== $match;
	}

	// ------------------------------------------------------------------
	// Calculations
	// ------------------------------------------------------------------

	/**
	 * Is a field worked out from other fields?
	 *
	 * @param mixed $field Field settings.
	 * @return bool
	 */
	public static function has_calc( $field ) {
		return is_array( $field ) && isset( $field['tfCalc'] ) && '' !== trim( (string) $field['tfCalc'] );
	}

	/**
	 * A calculation's decimals (0 to 6, 2 when not set).
	 *
	 * @param array $field Field settings.
	 * @return int
	 */
	private static function calc_decimals( $field ) {
		return isset( $field['tfCalcDecimals'] ) && '' !== (string) $field['tfCalcDecimals'] ? max( 0, min( 6, (int) $field['tfCalcDecimals'] ) ) : 2;
	}

	/**
	 * A formula with its fields numbered: [ "{0} * {1} + 25", [ field|null, … ] ].
	 *
	 * @param array  $fields  Field settings.
	 * @param string $formula The formula as written ("{Quantity} * {Price} + 25").
	 * @return array
	 */
	public static function calc_parts( $fields, $formula ) {
		$refs = array();
		$expr = (string) preg_replace_callback(
			'/\{([^{}]+)\}/',
			function ( $m ) use ( $fields, &$refs ) {
				$refs[] = self::find_field( $fields, $m[1] );
				return '{' . ( count( $refs ) - 1 ) . '}';
			},
			wp_strip_all_tags( $formula )
		);
		return array( $expr, $refs );
	}

	/**
	 * The first number in a value ("€ 1,250.50" → 1250.5, "1.250,50" → 1250.5,
	 * "2,5" → 2.5, "Premium 250" → 250; none → 0). The same as num() in the browser.
	 *
	 * @param string $v Value.
	 * @return float
	 */
	public static function to_number( $v ) {
		if ( ! preg_match( "/-?\\d[\\d.,\\s']*/", (string) $v, $m ) ) {
			return 0.0;
		}
		$t = rtrim( (string) preg_replace( "/[\\s']+/", '', $m[0] ), '.,' );
		$c = strrpos( $t, ',' );
		$d = strrpos( $t, '.' );
		if ( false !== $c && false !== $d ) {
			$t = $c > $d ? str_replace( ',', '.', str_replace( '.', '', $t ) ) : str_replace( ',', '', $t );
		} elseif ( false !== $c ) {
			$t = preg_match( '/^-?\d+,\d{1,2}$/', $t ) ? str_replace( ',', '.', $t ) : str_replace( ',', '', $t );
		}
		$n = (float) $t;
		return is_finite( $n ) ? $n : 0.0;
	}

	/**
	 * Work out a formula ("{0} * {1} + 25" with $vals[0], $vals[1]). The same
	 * rules as assets/front/form-extras.js; nothing is run as code.
	 *
	 * @param string  $expr Formula with numbered fields.
	 * @param float[] $vals Field values.
	 * @return float
	 */
	public static function calc_eval( $expr, $vals ) {
		$s   = (string) $expr;
		$len = strlen( $s );
		$i   = 0;
		$ws  = function () use ( $s, $len, &$i ) {
			while ( $i < $len && ctype_space( $s[ $i ] ) ) {
				$i++;
			}
		};
		$take = function ( $t ) use ( $s, $ws, &$i ) {
			$ws();
			if ( substr( $s, $i, strlen( $t ) ) === $t ) {
				$i += strlen( $t );
				return true;
			}
			return false;
		};
		$cmp  = null;
		$atom = function () use ( $s, $len, $ws, $take, $vals, &$i, &$cmp ) {
			$ws();
			if ( $take( '(' ) ) {
				$v = $cmp();
				$take( ')' );
				return $v;
			}
			if ( $i < $len && '{' === $s[ $i ] ) {
				$j = strpos( $s, '}', $i );
				$k = false === $j ? '' : substr( $s, $i + 1, $j - $i - 1 );
				$i = false === $j ? $len : $j + 1;
				return isset( $vals[ (int) $k ] ) ? (float) $vals[ (int) $k ] : 0.0;
			}
			if ( preg_match( '/^(\d+(?:\.\d+)?|\.\d+)/', substr( $s, $i ), $m ) ) {
				$i += strlen( $m[0] );
				return (float) $m[0];
			}
			if ( preg_match( '/^([a-z]+)\s*\(/i', substr( $s, $i ), $m ) ) {
				$i   += strlen( $m[0] );
				$args = array();
				if ( ! $take( ')' ) ) {
					do {
						$args[] = $cmp();
					} while ( $take( ',' ) );
					$take( ')' );
				}
				return self::calc_fn( strtolower( $m[1] ), $args );
			}
			$i = $len; // anything else ends the formula
			return 0.0;
		};
		$unary = null;
		$power = function () use ( $atom, $take, &$unary ) {
			$b = $atom();
			return $take( '^' ) ? (float) pow( $b, $unary() ) : $b;
		};
		$unary = function () use ( $take, $power, &$unary ) {
			if ( $take( '-' ) ) {
				return -$unary();
			}
			if ( $take( '+' ) ) {
				return $unary();
			}
			return $power();
		};
		$prod = function () use ( $take, $unary ) {
			$v = $unary();
			while ( true ) {
				if ( $take( '*' ) ) {
					$v *= $unary();
				} elseif ( $take( '/' ) ) {
					$d = $unary();
					$v = 0.0 != $d ? $v / $d : 0.0; // phpcs:ignore Universal.Operators.StrictComparisons -- floats
				} elseif ( $take( '%' ) ) {
					$d = $unary();
					$v = 0.0 != $d ? fmod( $v, $d ) : 0.0; // phpcs:ignore Universal.Operators.StrictComparisons -- floats
				} else {
					return $v;
				}
			}
		};
		$sum = function () use ( $take, $prod ) {
			$v = $prod();
			while ( true ) {
				if ( $take( '+' ) ) {
					$v += $prod();
				} elseif ( $take( '-' ) ) {
					$v -= $prod();
				} else {
					return $v;
				}
			}
		};
		$cmp = function () use ( $take, $sum ) {
			$a = $sum();
			foreach ( array( '>=', '<=', '!=', '==', '>', '<', '=' ) as $op ) {
				if ( $take( $op ) ) {
					$b = $sum();
					switch ( $op ) {
						case '>=':
							return $a >= $b ? 1.0 : 0.0;
						case '<=':
							return $a <= $b ? 1.0 : 0.0;
						case '!=':
							return abs( $a - $b ) > 1e-9 ? 1.0 : 0.0;
						case '>':
							return $a > $b ? 1.0 : 0.0;
						case '<':
							return $a < $b ? 1.0 : 0.0;
						default:
							return abs( $a - $b ) <= 1e-9 ? 1.0 : 0.0;
					}
				}
			}
			return $a;
		};
		$out = $cmp();
		return is_finite( $out ) ? $out : 0.0;
	}

	/**
	 * The functions a formula can use.
	 *
	 * @param string  $name Function.
	 * @param float[] $a    Arguments.
	 * @return float
	 */
	private static function calc_fn( $name, $a ) {
		$x = isset( $a[0] ) ? (float) $a[0] : 0.0;
		switch ( $name ) {
			case 'round':
				$f = pow( 10, max( 0, min( 10, (int) round( isset( $a[1] ) ? $a[1] : 0 ) ) ) );
				return floor( $x * $f + 0.5 ) / $f;
			case 'ceil':
				return ceil( $x );
			case 'floor':
				return floor( $x );
			case 'abs':
				return abs( $x );
			case 'min':
				return $a ? (float) min( $a ) : 0.0;
			case 'max':
				return $a ? (float) max( $a ) : 0.0;
			case 'if':
				return (float) ( 0.0 != $x ? ( isset( $a[1] ) ? $a[1] : 0 ) : ( isset( $a[2] ) ? $a[2] : 0 ) ); // phpcs:ignore Universal.Operators.StrictComparisons -- floats
		}
		return 0.0;
	}

	/**
	 * A calculated field's value as sent: the number with its decimals, and for a
	 * text field the text before and after it.
	 *
	 * @param array $field Field settings.
	 * @param float $n     Number.
	 * @return string
	 */
	public static function calc_text( $field, $n ) {
		$text = number_format( self::calc_round( $field, $n ), self::calc_decimals( $field ), '.', '' );
		if ( isset( $field['type'] ) && in_array( $field['type'], array( 'number', 'hidden' ), true ) ) {
			return $text;
		}
		$pre = isset( $field['tfCalcPrefix'] ) ? wp_kses( (string) $field['tfCalcPrefix'], array() ) : '';
		$suf = isset( $field['tfCalcSuffix'] ) ? wp_kses( (string) $field['tfCalcSuffix'], array() ) : '';
		return $pre . self::group_digits( $text, self::calc_format( $field ) ) . $suf;
	}

	/**
	 * A result rounded to the field's decimals (what other results count with).
	 *
	 * @param array $field Field settings.
	 * @param float $n     Number.
	 * @return float
	 */
	public static function calc_round( $field, $n ) {
		$f = pow( 10, self::calc_decimals( $field ) );
		return floor( $n * $f + 0.5 ) / $f;
	}

	/** Thousands and decimal separators per number format. */
	const FORMATS = array(
		'comma'      => array( ',', '.' ),
		'dot'        => array( '.', ',' ),
		'space'      => array( ' ', ',' ),
		'apostrophe' => array( "'", '.' ),
	);

	/**
	 * Choices for "Number format".
	 *
	 * @return array
	 */
	private static function format_options() {
		return array(
			''           => '1234.50',
			'comma'      => '1,234.50',
			'dot'        => '1.234,50',
			'space'      => '1 234,50',
			'apostrophe' => "1'234.50",
		);
	}

	/**
	 * A result's number format ('' plain, or a FORMATS key).
	 *
	 * @param array $field Field settings.
	 * @return string
	 */
	private static function calc_format( $field ) {
		return isset( $field['tfCalcFormat'] ) && is_string( $field['tfCalcFormat'] ) && array_key_exists( $field['tfCalcFormat'], self::FORMATS ) ? (string) $field['tfCalcFormat'] : '';
	}

	/**
	 * "1234567.50" in a number format ("1.234.567,50"). The same as group() in the browser.
	 *
	 * @param string $text   Plain number text.
	 * @param string $format FORMATS key or ''.
	 * @return string
	 */
	public static function group_digits( $text, $format ) {
		if ( '' === $format || ! array_key_exists( $format, self::FORMATS ) ) {
			return $text;
		}
		list( $thousands, $decimal ) = self::FORMATS[ $format ];
		$neg   = '-' === substr( $text, 0, 1 );
		$parts = explode( '.', $neg ? substr( $text, 1 ) : $text, 2 );
		$int   = (string) preg_replace( '/\B(?=(\d{3})+(?!\d))/', $thousands, $parts[0] );
		return ( $neg ? '-' : '' ) . $int . ( isset( $parts[1] ) && '' !== $parts[1] ? $decimal . $parts[1] : '' );
	}

	/**
	 * Work out every calculated field of a submission (the visitor's own numbers
	 * are ignored): field id => value as sent. Fields a submission hides count as 0.
	 *
	 * @param array    $fields    Field settings.
	 * @param array    $submitted Submitted values.
	 * @param string[] $hidden    Ids of hidden fields.
	 * @return array
	 */
	public static function calculate( $fields, $submitted, $hidden ) {
		$fields = array_values( $fields );
		$calc   = array_filter( $fields, array( __CLASS__, 'has_calc' ) );
		$out    = array();
		$raw    = array(); // id => the rounded number, for results that use a result
		// A few passes: a result can use another result.
		for ( $pass = 0; $pass < 5; $pass++ ) {
			$changed = false;
			foreach ( $calc as $field ) {
				if ( empty( $field['id'] ) || in_array( $field['id'], $hidden, true ) ) {
					continue;
				}
				list( $expr, $refs ) = self::calc_parts( $fields, (string) $field['tfCalc'] );
				$vals = array();
				foreach ( $refs as $ref ) {
					if ( ! $ref || in_array( $ref['id'], $hidden, true ) ) {
						$vals[] = 0.0;
						continue;
					}
					if ( isset( $raw[ $ref['id'] ] ) ) {
						$vals[] = $raw[ $ref['id'] ];
						continue;
					}
					$vals[] = array_sum( array_map( array( __CLASS__, 'to_number' ), self::submitted( $submitted, $ref ) ) );
				}
				$n                    = self::calc_eval( $expr, $vals );
				$raw[ $field['id'] ]  = self::calc_round( $field, $n );
				$text                 = self::calc_text( $field, $n );
				if ( ! isset( $out[ $field['id'] ] ) || $out[ $field['id'] ] !== $text ) {
					$out[ $field['id'] ] = $text;
					$changed             = true;
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * On submit: fields whose condition hides them are not required.
	 *
	 * The builder checks required fields right after this filter, from the
	 * form's settings, so the "required" flag of hidden fields is dropped there
	 * for this submission only (nothing is saved).
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
		$hidden    = self::hidden_fields( $fields, $submitted );
		// Required for this submission: hidden fields never; "Required only when" fields when their lines hold.
		$need = self::required_now( $fields, $submitted, $hidden );
		foreach ( $hidden as $id ) {
			$need[ $id ] = false;
		}
		if ( $need && property_exists( $form, 'form_settings' ) ) {
			$set_required = function ( $need ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName -- the form handler's own property
				foreach ( $this->form_settings['fields'] as $i => $f ) {
					if ( ! isset( $f['id'] ) || ! array_key_exists( $f['id'], $need ) ) {
						continue;
					}
					if ( $need[ $f['id'] ] ) {
						$this->form_settings['fields'][ $i ]['required'] = true;
					} else {
						unset( $this->form_settings['fields'][ $i ]['required'] );
					}
				}
			};
			$bound = \Closure::bind( $set_required, $form, get_class( $form ) );
			if ( $bound ) {
				$bound( $need );
			}
		}
		// Calculated fields: worked out again here, so the email and the stored
		// submission get the right result whatever the browser sent.
		$calc = self::calculate( $fields, $submitted, $hidden );
		if ( $calc && property_exists( $form, 'form_fields' ) ) {
			$names = array();
			foreach ( $fields as $f ) {
				if ( isset( $f['id'], $calc[ $f['id'] ] ) ) {
					$names[ $f['id'] ] = self::input_name( $f );
				}
			}
			$set   = function ( $calc, $names ) {
				foreach ( $calc as $id => $value ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName -- the form handler's own property
					$this->form_fields[ 'form-field-' . $id ] = $value;
					if ( isset( $names[ $id ] ) ) {
						$this->form_fields[ $names[ $id ] ] = $value;
					}
				}
			};
			$bound = \Closure::bind( $set, $form, get_class( $form ) );
			if ( $bound ) {
				$bound( $calc, $names );
			}
		}
		return $errors;
	}

	/**
	 * Ids of the fields a submission hides: fields whose condition fails, and
	 * every field of a step whose own condition fails. Conditions are evaluated
	 * like in the browser, so a field whose controlling field is hidden counts
	 * as empty.
	 *
	 * @param array $fields    Field settings.
	 * @param array $submitted Submitted values (form-field-{id} or the field's own name).
	 * @return string[]
	 */
	public static function hidden_fields( $fields, $submitted ) {
		$fields  = array_values( $fields );
		$step_of = array();
		$starts  = array();
		$step    = 0;
		foreach ( $fields as $i => $field ) {
			if ( $i > 0 && is_array( $field ) && ! empty( $field['tfStep'] ) ) {
				$step++;
				$starts[ $step ] = $field;
			}
			$step_of[ $i ] = $step;
		}
		$hidden    = array();
		$values_of = self::reader( $fields, $submitted, $hidden );
		for ( $pass = 0; $pass < 6; $pass++ ) {
			$skipped = array();
			foreach ( $starts as $n => $start ) {
				if ( self::has_step_condition( $start ) && ! self::rules_hold( self::field_rules( $start, true ), self::match( $start, 'tfStepShow' ), $values_of ) ) {
					$skipped[ $n ] = true;
				}
			}
			$now = array();
			foreach ( $fields as $i => $field ) {
				if ( ! is_array( $field ) || empty( $field['id'] ) ) {
					continue;
				}
				if ( isset( $skipped[ $step_of[ $i ] ] ) ) {
					$now[] = $field['id'];
				} elseif ( self::has_condition( $field ) && ! self::rules_hold( self::field_rules( $field, false ), self::match( $field, 'tfShow' ), $values_of ) ) {
					$now[] = $field['id'];
				}
			}
			$same   = array_values( array_diff( $now, $hidden ) ) === array() && array_values( array_diff( $hidden, $now ) ) === array();
			$hidden = $now;
			if ( $same ) {
				break;
			}
		}
		return $hidden;
	}

	/**
	 * Does a condition hold? The same rules as assets/front/form-extras.js.
	 *
	 * @param string   $op     Operator.
	 * @param string   $value  Value to compare with.
	 * @param string[] $values The other field's values (several for checkboxes).
	 * @return bool
	 */
	public static function test( $op, $value, $values ) {
		$value  = strtolower( trim( $value ) );
		$values = array_map(
			function ( $v ) {
				return strtolower( trim( (string) $v ) );
			},
			(array) $values
		);
		$filled = array_filter(
			$values,
			function ( $v ) {
				return '' !== $v;
			}
		);
		switch ( $op ) {
			case 'empty':
				return ! $filled;
			case 'notempty':
				return (bool) $filled;
			case 'isnot':
				return ! in_array( $value, $values, true );
			case 'contains':
				foreach ( $filled as $v ) {
					if ( '' !== $value && false !== strpos( $v, $value ) ) {
						return true;
					}
				}
				return false;
			case 'gt':
			case 'lt':
			case 'gte':
			case 'lte':
				foreach ( $filled as $v ) {
					$a = self::to_number( $v );
					$b = self::to_number( $value );
					if ( ! preg_match( '/\d/', $value ) || ! preg_match( '/\d/', $v ) ) {
						continue; // a number is compared with a number only
					}
					if ( ( 'gt' === $op && $a > $b ) || ( 'lt' === $op && $a < $b ) || ( 'gte' === $op && $a >= $b ) || ( 'lte' === $op && $a <= $b ) ) {
						return true;
					}
				}
				return false;
			default:
				return in_array( $value, $values, true );
		}
	}

	/**
	 * Number of steps (1 when no field starts a new step).
	 *
	 * @param array $fields Field settings.
	 * @return int
	 */
	public static function step_count( $fields ) {
		$n = 1;
		foreach ( array_values( $fields ) as $i => $field ) {
			if ( $i > 0 && ! empty( $field['tfStep'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Has a field a condition?
	 *
	 * @param mixed $field Field settings.
	 * @return bool
	 */
	public static function has_condition( $field ) {
		return (bool) self::field_rules( $field, false );
	}

	/**
	 * The field a condition points at: by ID, name or label (not case sensitive).
	 *
	 * @param array  $fields Field settings.
	 * @param string $ref    What the condition names.
	 * @return array|null
	 */
	public static function find_field( $fields, $ref ) {
		$ref = strtolower( trim( wp_strip_all_tags( $ref ) ) );
		if ( '' === $ref ) {
			return null;
		}
		foreach ( array( 'id', 'name', 'label' ) as $prop ) {
			foreach ( $fields as $field ) {
				if ( is_array( $field ) && isset( $field[ $prop ], $field['id'] ) && strtolower( trim( wp_strip_all_tags( (string) $field[ $prop ] ) ) ) === $ref ) {
					return $field;
				}
			}
		}
		return null;
	}

	/**
	 * The name a field's input is sent under.
	 *
	 * @param array $field Field settings.
	 * @return string
	 */
	public static function input_name( $field ) {
		return ! empty( $field['name'] ) ? (string) $field['name'] : 'form-field-' . $field['id'];
	}

	/**
	 * A field's submitted values.
	 *
	 * @param array $submitted Submitted values.
	 * @param array $field     Field settings.
	 * @return string[]
	 */
	private static function submitted( $submitted, $field ) {
		foreach ( array( self::input_name( $field ), 'form-field-' . $field['id'] ) as $name ) {
			if ( isset( $submitted[ $name ] ) ) {
				$v = $submitted[ $name ];
				return is_array( $v ) ? array_map( 'strval', $v ) : array( (string) $v );
			}
		}
		return array();
	}

	/**
	 * A field's operator (sanitised).
	 *
	 * @param array $field Field settings.
	 * @return string
	 */
	private static function op( $field, $key = 'tfShowOp' ) {
		return isset( $field[ $key ] ) && in_array( $field[ $key ], self::OPS, true ) ? $field[ $key ] : 'is';
	}

	/**
	 * The operators, as the builder's select shows them.
	 *
	 * @return array
	 */
	private static function op_options() {
		return array(
			'is'       => esc_html__( 'is', 'tokenforge' ),
			'isnot'    => esc_html__( 'is not', 'tokenforge' ),
			'contains' => esc_html__( 'contains', 'tokenforge' ),
			'empty'    => esc_html__( 'is empty', 'tokenforge' ),
			'notempty' => esc_html__( 'is not empty', 'tokenforge' ),
			'gt'       => esc_html__( 'is more than', 'tokenforge' ),
			'lt'       => esc_html__( 'is less than', 'tokenforge' ),
			'gte'      => esc_html__( 'is at least', 'tokenforge' ),
			'lte'      => esc_html__( 'is at most', 'tokenforge' ),
		);
	}

	/**
	 * Does a field start a step that has a condition of its own?
	 *
	 * @param mixed $field Field settings.
	 * @return bool
	 */
	public static function has_step_condition( $field ) {
		return (bool) self::field_rules( $field, true );
	}

	/**
	 * A text setting or its default.
	 *
	 * @param array  $settings Settings.
	 * @param string $key      Key.
	 * @param string $default  Default.
	 * @return string
	 */
	private static function text( $settings, $key, $default ) {
		$v = isset( $settings[ $key ] ) ? trim( wp_strip_all_tags( (string) $settings[ $key ] ) ) : '';
		return '' !== $v ? $v : $default;
	}

	/**
	 * A choice setting or its default.
	 *
	 * @param array    $settings Settings.
	 * @param string   $key      Key.
	 * @param string[] $allowed  Allowed values.
	 * @param string   $default  Default.
	 * @return string
	 */
	private static function choice( $settings, $key, $allowed, $default ) {
		return isset( $settings[ $key ] ) && in_array( $settings[ $key ], $allowed, true ) ? $settings[ $key ] : $default;
	}
}
