<?php
/**
 * Limits, submit rules and two extra actions for the builder's own form.
 *
 * Limits (switch form_limits): close a form after a number of sends, only
 * signed-in visitors, once per user (or once every N days), and a submit
 * button that stays disabled or hidden until condition lines hold. Checked
 * when the form is shown (a message replaces it) and again on submit.
 * Dynamic tags {tf_form_count:formId} and {tf_form_left:formId}.
 *
 * Actions (switch form_actions), in the builder's own Actions list:
 * - tf-meta: update post meta (the form's post, or another post the visitor
 *   may edit) or the signed-in user's meta; replace, add, subtract, add to a
 *   list, remove from a list. Protected and role keys are refused.
 * - tf-cart: add a WooCommerce product to the cart, optionally priced by a
 *   calculated field that is worked out again here (never the browser's number).
 *
 * Hooks: bricks/elements/form/controls, bricks/elements/form/control_groups,
 * bricks/element/render_attributes, bricks/frontend/render_element,
 * bricks/form/validate, bricks/form/response, bricks/form/action/{name},
 * bricks/dynamic_tags_list, bricks/dynamic_data/render_tag and render_content;
 * WooCommerce cart and order item hooks for the cart action.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Limits, submit rules, meta and cart actions.
 */
class Form_Rules {

	/** Option: sends per form id (forms without Save submission). */
	const SENDS = 'tokenforge_form_sends';

	/** User meta: form id => time of the user's last send. */
	const SENT = 'tokenforge_form_sent';

	/** Cart item key for the cart action's data. */
	const CART = 'tokenforge_form';

	/**
	 * Parts switched on.
	 *
	 * @var array
	 */
	private $on = array();

	/**
	 * Hooks for the parts that are switched on.
	 */
	public function __construct() {
		$s        = Components::settings();
		$this->on = array(
			'limits'  => ! empty( $s['form_limits'] ),
			'actions' => ! empty( $s['form_actions'] ),
			'cart'    => ! empty( $s['form_actions'] ) && class_exists( 'WooCommerce' ),
		);
		if ( ! $this->on['limits'] && ! $this->on['actions'] ) {
			return;
		}
		add_filter( 'bricks/elements/form/control_groups', array( $this, 'control_groups' ) );
		add_filter( 'bricks/elements/form/controls', array( $this, 'controls' ), 12 );
		add_filter( 'bricks/element/render_attributes', array( $this, 'attributes' ), 10, 3 );
		if ( $this->on['limits'] ) {
			add_filter( 'bricks/frontend/render_element', array( $this, 'closed_form' ), 10, 2 );
			add_filter( 'bricks/form/validate', array( $this, 'validate' ), 12, 2 );
			add_filter( 'bricks/form/response', array( $this, 'sent' ), 10, 2 );
			add_filter( 'bricks/dynamic_tags_list', array( $this, 'tags' ) );
			add_filter( 'bricks/dynamic_data/render_tag', array( $this, 'render_tag' ), 10, 3 );
			add_filter( 'bricks/dynamic_data/render_content', array( $this, 'render_content' ), 10, 3 );
		}
		if ( $this->on['actions'] ) {
			add_action( 'bricks/form/action/tf-meta', array( $this, 'run_meta' ) );
		}
		if ( $this->on['cart'] ) {
			add_action( 'bricks/form/action/tf-cart', array( $this, 'run_cart' ) );
			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'cart_prices' ), 20 );
			add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'session_price' ), 20, 2 );
			add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item_meta' ), 10, 3 );
		}
	}

	// ------------------------------------------------------------------
	// Builder settings
	// ------------------------------------------------------------------

	/**
	 * Control groups.
	 *
	 * @param array $groups Groups.
	 * @return array
	 */
	public function control_groups( $groups ) {
		if ( $this->on['limits'] ) {
			$groups['tfLimits'] = array(
				'title' => esc_html__( 'Limits and sending (TokenForge)', 'tokenforge' ),
				'tab'   => 'content',
			);
		}
		if ( $this->on['actions'] ) {
			$groups['tfMeta'] = array(
				'title'    => esc_html__( 'Update meta (TokenForge)', 'tokenforge' ),
				'tab'      => 'content',
				'required' => array( 'actions', '=', 'tf-meta' ),
			);
		}
		if ( $this->on['cart'] ) {
			$groups['tfCart'] = array(
				'title'    => esc_html__( 'Add to cart (TokenForge)', 'tokenforge' ),
				'tab'      => 'content',
				'required' => array( 'actions', '=', 'tf-cart' ),
			);
		}
		return $groups;
	}

	/**
	 * Settings for limits and the two actions.
	 *
	 * @param array $controls Controls.
	 * @return array
	 */
	public function controls( $controls ) {
		if ( $this->on['limits'] ) {
			$controls += array(
				'tfMax'         => array(
					'group'       => 'tfLimits',
					'label'       => esc_html__( 'Close after this many sends', 'tokenforge' ),
					'type'        => 'number',
					'min'         => 1,
					'placeholder' => esc_html__( 'No limit', 'tokenforge' ),
					'description' => esc_html__( 'With Save submission among the actions, the saved submissions count (deleting one opens a place again); otherwise TokenForge counts the sends. Show places left with {tf_form_left:form ID}.', 'tokenforge' ),
				),
				'tfClosedText'  => array(
					'group'       => 'tfLimits',
					'label'       => esc_html__( 'Message when closed', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'This form is closed.', 'tokenforge' ),
					'required'    => array( 'tfMax', '!=', '' ),
				),
				'tfLoggedIn'    => array(
					'group' => 'tfLimits',
					'label' => esc_html__( 'Only signed-in visitors can send', 'tokenforge' ),
					'type'  => 'checkbox',
				),
				'tfLoginText'   => array(
					'group'       => 'tfLimits',
					'label'       => esc_html__( 'Message for visitors not signed in', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'Please sign in to use this form.', 'tokenforge' ),
					'required'    => array( 'tfLoggedIn', '=', true ),
				),
				'tfOnce'        => array(
					'group'       => 'tfLimits',
					'label'       => esc_html__( 'Sends per user', 'tokenforge' ),
					'type'        => 'select',
					'options'     => array(
						'once' => esc_html__( 'Once', 'tokenforge' ),
						'days' => esc_html__( 'Once every few days', 'tokenforge' ),
					),
					'placeholder' => esc_html__( 'No limit', 'tokenforge' ),
					'description' => esc_html__( 'Counts signed-in users only, so it also switches on "Only signed-in visitors can send".', 'tokenforge' ),
				),
				'tfOnceDays'    => array(
					'group'       => 'tfLimits',
					'label'       => esc_html__( 'Days between sends', 'tokenforge' ),
					'type'        => 'number',
					'min'         => 1,
					'placeholder' => '30',
					'required'    => array( 'tfOnce', '=', 'days' ),
				),
				'tfSentText'    => array(
					'group'       => 'tfLimits',
					'label'       => esc_html__( 'Message after a user\'s send', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'You have already sent this form.', 'tokenforge' ),
					'required'    => array( 'tfOnce', '!=', '' ),
				),
				'tfSubmitRules' => array(
					'group'       => 'tfLimits',
					'label'       => esc_html__( 'Send button works only when (one per line)', 'tokenforge' ),
					'type'        => 'textarea',
					'placeholder' => "{Terms} not empty\n{Total} > 0",
					'description' => esc_html__( 'Each line: a field in braces (its label or ID), then = != > < >= <= contains, empty or not empty, then the value. Checked again on the server.', 'tokenforge' ),
				),
				'tfSubmitMatch' => array(
					'group'       => 'tfLimits',
					'label'       => esc_html__( 'Works when', 'tokenforge' ),
					'type'        => 'select',
					'options'     => array(
						'all' => esc_html__( 'All conditions hold', 'tokenforge' ),
						'any' => esc_html__( 'Any condition holds', 'tokenforge' ),
					),
					'placeholder' => esc_html__( 'All conditions hold', 'tokenforge' ),
					'required'    => array( 'tfSubmitRules', '!=', '' ),
				),
				'tfSubmitMode'  => array(
					'group'       => 'tfLimits',
					'label'       => esc_html__( 'Until then the button is', 'tokenforge' ),
					'type'        => 'select',
					'options'     => array(
						'disable' => esc_html__( 'Disabled', 'tokenforge' ),
						'hide'    => esc_html__( 'Hidden', 'tokenforge' ),
					),
					'placeholder' => esc_html__( 'Disabled', 'tokenforge' ),
					'required'    => array( 'tfSubmitRules', '!=', '' ),
				),
			);
		}
		if ( $this->on['actions'] && isset( $controls['actions']['options'] ) && is_array( $controls['actions']['options'] ) ) {
			$controls['actions']['options']['tf-meta'] = esc_html__( 'Update meta (TokenForge)', 'tokenforge' );
			if ( $this->on['cart'] ) {
				$controls['actions']['options']['tf-cart'] = esc_html__( 'Add to cart (TokenForge)', 'tokenforge' );
			}
			$controls += array(
				'tfMetaTarget' => array(
					'group'       => 'tfMeta',
					'label'       => esc_html__( 'Write to', 'tokenforge' ),
					'type'        => 'select',
					'options'     => array(
						'post' => esc_html__( 'A post', 'tokenforge' ),
						'user' => esc_html__( 'The signed-in user', 'tokenforge' ),
					),
					'placeholder' => esc_html__( 'A post', 'tokenforge' ),
				),
				'tfMetaPost'   => array(
					'group'       => 'tfMeta',
					'label'       => esc_html__( 'Post ID', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'The post the form is on', 'tokenforge' ),
					'description' => esc_html__( 'Dynamic data and {{field ID}} work. Another post than the form\'s own needs a signed-in visitor who may edit it.', 'tokenforge' ),
					'required'    => array( 'tfMetaTarget', '!=', 'user' ),
				),
				'tfMetaWho'    => array(
					'group'       => 'tfMeta',
					'label'       => esc_html__( 'Who may write', 'tokenforge' ),
					'type'        => 'select',
					'options'     => array(
						'editors' => esc_html__( 'Visitors who may edit that post', 'tokenforge' ),
						'anyone'  => esc_html__( 'Anyone (only the post the form is on)', 'tokenforge' ),
					),
					'placeholder' => esc_html__( 'Visitors who may edit that post', 'tokenforge' ),
					'description' => esc_html__( 'Anyone: for counters, votes or sign-up lists on the page itself.', 'tokenforge' ),
					'required'    => array( 'tfMetaTarget', '!=', 'user' ),
				),
				'tfMetaRows'   => array(
					'group'         => 'tfMeta',
					'label'         => esc_html__( 'Fields to write', 'tokenforge' ),
					'type'          => 'repeater',
					'titleProperty' => 'key',
					'placeholder'   => esc_html__( 'Meta field', 'tokenforge' ),
					'fields'        => array(
						'key'       => array(
							'label'       => esc_html__( 'Meta key', 'tokenforge' ),
							'type'        => 'text',
							'placeholder' => 'votes',
							'description' => esc_html__( 'An ACF field name works too. Keys starting with _ and role keys are refused.', 'tokenforge' ),
						),
						'value'     => array(
							'label'       => esc_html__( 'Value', 'tokenforge' ),
							'type'        => 'text',
							'placeholder' => '{{field ID}}',
						),
						'mode'      => array(
							'label'       => esc_html__( 'How', 'tokenforge' ),
							'type'        => 'select',
							'options'     => array(
								'replace'  => esc_html__( 'Replace', 'tokenforge' ),
								'add'      => esc_html__( 'Add to the number', 'tokenforge' ),
								'subtract' => esc_html__( 'Subtract from the number', 'tokenforge' ),
								'append'   => esc_html__( 'Add to a list', 'tokenforge' ),
								'remove'   => esc_html__( 'Remove from a list', 'tokenforge' ),
							),
							'placeholder' => esc_html__( 'Replace', 'tokenforge' ),
						),
						'skipEmpty' => array(
							'label' => esc_html__( 'Skip when empty', 'tokenforge' ),
							'type'  => 'checkbox',
						),
					),
				),
			);
		}
		if ( $this->on['cart'] ) {
			$controls += array(
				'tfCartProduct' => array(
					'group'       => 'tfCart',
					'label'       => esc_html__( 'Product or variation ID', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => '{post_id}',
					'description' => esc_html__( 'Dynamic data or {{field ID}} (for example a select filled with products).', 'tokenforge' ),
				),
				'tfCartQty'     => array(
					'group'       => 'tfCart',
					'label'       => esc_html__( 'Quantity', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => '1',
				),
				'tfCartPrice'   => array(
					'group'       => 'tfCart',
					'label'       => esc_html__( 'Price from the calculated field', 'tokenforge' ),
					'type'        => 'text',
					'placeholder' => esc_html__( 'Label or ID of a field with Calculate', 'tokenforge' ),
					'description' => esc_html__( 'The price per item. Worked out again on the server from the answers, so a visitor cannot change it. Empty: the product\'s own price.', 'tokenforge' ),
				),
				'tfCartAnswers' => array(
					'group' => 'tfCart',
					'label' => esc_html__( 'Show the answers in the cart and the order', 'tokenforge' ),
					'type'  => 'checkbox',
				),
				'tfCartGo'      => array(
					'group'       => 'tfCart',
					'label'       => esc_html__( 'Then', 'tokenforge' ),
					'type'        => 'select',
					'options'     => array(
						'cart'     => esc_html__( 'Go to the cart', 'tokenforge' ),
						'checkout' => esc_html__( 'Go to the checkout', 'tokenforge' ),
					),
					'placeholder' => esc_html__( 'Stay on the page', 'tokenforge' ),
				),
			);
		}
		return $controls;
	}

	// ------------------------------------------------------------------
	// Front end
	// ------------------------------------------------------------------

	/**
	 * Submit rules and the cart flag on the form.
	 *
	 * @param array  $attributes All attributes of the element, by key.
	 * @param string $key        The key being printed.
	 * @param object $element    Element.
	 * @return array
	 */
	public function attributes( $attributes, $key, $element ) {
		if ( '_root' !== $key || ! is_object( $element ) || ! isset( $element->name ) || 'form' !== $element->name ) {
			return $attributes;
		}
		$settings = is_array( $element->settings ) ? $element->settings : array();
		$fields   = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? array_values( $settings['fields'] ) : array();
		$used     = false;
		if ( $this->on['limits'] && ! empty( $settings['tfSubmitRules'] ) ) {
			$rules = Form_Extras::browser_rules( $fields, Form_Extras::parse_rules( $settings['tfSubmitRules'] ), self::submit_match( $settings ) );
			if ( $rules['rules'] ) {
				$rules['mode']                          = isset( $settings['tfSubmitMode'] ) && 'hide' === $settings['tfSubmitMode'] ? 'hide' : 'disable';
				$attributes['_root']['data-tf-submit'] = wp_json_encode( $rules );
				$used                                  = true;
			}
		}
		if ( $this->on['cart'] && ! empty( $settings['actions'] ) && in_array( 'tf-cart', (array) $settings['actions'], true ) ) {
			$attributes['_root']['data-tf-cart'] = '1';
			$used                                = true;
		}
		if ( $used ) {
			$attributes['_root']['data-tf-form'] = '1';
			Form_Extras::enqueue();
		}
		return $attributes;
	}

	/**
	 * "all" or "any" for the submit rules.
	 *
	 * @param array $settings Form settings.
	 * @return string
	 */
	private static function submit_match( $settings ) {
		return isset( $settings['tfSubmitMatch'] ) && 'any' === $settings['tfSubmitMatch'] ? 'any' : 'all';
	}

	/**
	 * A closed form shows its message instead (never in the builder).
	 *
	 * @param string $html     The element's HTML.
	 * @param object $instance The element.
	 * @return string
	 */
	public function closed_form( $html, $instance ) {
		if ( ! is_object( $instance ) || ! isset( $instance->name ) || 'form' !== $instance->name || self::in_builder() ) {
			return $html;
		}
		$settings = is_array( $instance->settings ) ? $instance->settings : array();
		$element  = isset( $instance->element ) && is_array( $instance->element ) ? $instance->element : array( 'id' => $instance->id );
		$msg      = self::blocked( $settings, self::element_form_id( $element ) );
		if ( '' === $msg ) {
			return $html;
		}
		return '<div class="tf-form-closed" role="status" data-tf-form-id="' . esc_attr( $instance->id ) . '">' . esc_html( $msg ) . '</div>';
	}

	/**
	 * In the builder or its preview?
	 *
	 * @return bool
	 */
	private static function in_builder() {
		return ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) || ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() );
	}

	/**
	 * The id a form's submissions are stored under (a global form's own id).
	 *
	 * @param array $element Element data.
	 * @return string
	 */
	private static function element_form_id( $element ) {
		$global = class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'get_global_element' ) ? \Bricks\Helpers::get_global_element( $element, 'global' ) : '';
		return (string) ( $global ? $global : ( isset( $element['id'] ) ? $element['id'] : '' ) );
	}

	/**
	 * Why the current visitor cannot send a form ('' when they can).
	 *
	 * @param array  $settings Form settings.
	 * @param string $form_id  Form id.
	 * @return string
	 */
	public static function blocked( $settings, $form_id ) {
		$max = isset( $settings['tfMax'] ) && is_numeric( $settings['tfMax'] ) ? (int) $settings['tfMax'] : 0;
		if ( $max > 0 && self::count( $form_id, $settings ) >= $max ) {
			return self::text( $settings, 'tfClosedText', __( 'This form is closed.', 'tokenforge' ) );
		}
		$once = isset( $settings['tfOnce'] ) && in_array( $settings['tfOnce'], array( 'once', 'days' ), true ) ? $settings['tfOnce'] : '';
		if ( ( ! empty( $settings['tfLoggedIn'] ) || $once ) && ! is_user_logged_in() ) {
			return self::text( $settings, 'tfLoginText', __( 'Please sign in to use this form.', 'tokenforge' ) );
		}
		if ( $once ) {
			$sent = get_user_meta( get_current_user_id(), self::SENT, true );
			$last = is_array( $sent ) && isset( $sent[ $form_id ] ) ? (int) $sent[ $form_id ] : 0;
			$days = isset( $settings['tfOnceDays'] ) && is_numeric( $settings['tfOnceDays'] ) ? max( 1, (int) $settings['tfOnceDays'] ) : 30;
			if ( $last && ( 'once' === $once || time() - $last < $days * DAY_IN_SECONDS ) ) {
				return self::text( $settings, 'tfSentText', __( 'You have already sent this form.', 'tokenforge' ) );
			}
		}
		return '';
	}

	/**
	 * How many times a form was sent: its saved submissions when it saves them,
	 * else TokenForge's own count.
	 *
	 * @param string     $form_id  Form id.
	 * @param array|null $settings Form settings (null: unknown).
	 * @return int
	 */
	public static function count( $form_id, $settings = null ) {
		$saves = is_array( $settings ) && ! empty( $settings['actions'] ) && in_array( 'save-submission', (array) $settings['actions'], true );
		if ( $saves && class_exists( '\Bricks\Integrations\Form\Submission_Database' ) ) {
			return (int) \Bricks\Integrations\Form\Submission_Database::get_entries_count( $form_id );
		}
		$all = get_option( self::SENDS, array() );
		return is_array( $all ) && isset( $all[ $form_id ] ) ? (int) $all[ $form_id ] : 0;
	}

	/**
	 * On submit: refuse a closed form, a visitor who may not send it, or a
	 * send button whose conditions do not hold.
	 *
	 * @param array  $errors Validation errors so far.
	 * @param object $form   The builder's form handler.
	 * @return array
	 */
	public function validate( $errors, $form ) {
		if ( ! is_object( $form ) || ! method_exists( $form, 'get_settings' ) || ! method_exists( $form, 'get_fields' ) ) {
			return $errors;
		}
		$settings = $form->get_settings();
		$msg      = self::blocked( $settings, self::submission_id( $form ) );
		if ( '' !== $msg ) {
			$errors[] = $msg;
			return $errors;
		}
		if ( ! empty( $settings['tfSubmitRules'] ) ) {
			$fields = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? $settings['fields'] : array();
			if ( Form_Extras::parse_rules( $settings['tfSubmitRules'] ) && ! Form_Extras::lines_hold( $fields, (array) $form->get_fields(), $settings['tfSubmitRules'], self::submit_match( $settings ) ) ) {
				$errors[] = __( 'Please complete the form before sending it.', 'tokenforge' );
			}
		}
		// The builder calls bricks/form/response (where sends are counted) only when
		// some action left a result; a silent success makes sure every send counts.
		if ( ! $errors && method_exists( $form, 'set_result' ) ) {
			$form->set_result(
				array(
					'action' => 'tf-limits',
					'type'   => 'success',
				)
			);
		}
		return $errors;
	}

	/**
	 * The id a submission is stored and limited under.
	 *
	 * @param object $form The builder's form handler.
	 * @return string
	 */
	private static function submission_id( $form ) {
		return (string) ( method_exists( $form, 'get_submission_id' ) ? $form->get_submission_id() : $form->get_id() );
	}

	/**
	 * After a successful send: count it, and remember it for the user.
	 *
	 * @param array  $response The response for the browser.
	 * @param object $form     The builder's form handler.
	 * @return array
	 */
	public function sent( $response, $form ) {
		if ( ! is_object( $form ) || ! method_exists( $form, 'get_id' ) ) {
			return $response;
		}
		$id = self::submission_id( $form );
		if ( '' === $id ) {
			return $response;
		}
		// ponytail: read-modify-write of one option, two sends in the same instant can count once; an atomic counter table if exact limits under load matter.
		$all        = get_option( self::SENDS, array() );
		$all        = is_array( $all ) ? $all : array();
		$all[ $id ] = ( isset( $all[ $id ] ) ? (int) $all[ $id ] : 0 ) + 1;
		update_option( self::SENDS, $all, false );
		$settings = method_exists( $form, 'get_settings' ) ? $form->get_settings() : array();
		$user     = get_current_user_id();
		if ( $user && ! empty( $settings['tfOnce'] ) ) {
			$sent        = get_user_meta( $user, self::SENT, true );
			$sent        = is_array( $sent ) ? $sent : array();
			$sent[ $id ] = time();
			update_user_meta( $user, self::SENT, $sent );
		}
		return $response;
	}

	// ------------------------------------------------------------------
	// Tags: {tf_form_count:formId} and {tf_form_left:formId}
	// ------------------------------------------------------------------

	/**
	 * The tags in the builder's dynamic data list.
	 *
	 * @param array $tags Tags.
	 * @return array
	 */
	public function tags( $tags ) {
		$tags[] = array(
			'name'  => '{tf_form_count:form ID}',
			'label' => __( 'Form: times sent (put the form\'s element ID after the colon)', 'tokenforge' ),
			'group' => 'TokenForge',
		);
		$tags[] = array(
			'name'  => '{tf_form_left:form ID}',
			'label' => __( 'Form: places left before it closes', 'tokenforge' ),
			'group' => 'TokenForge',
		);
		return $tags;
	}

	/**
	 * One tag on its own.
	 *
	 * @param mixed  $tag     The tag without braces.
	 * @param mixed  $post    Post.
	 * @param string $context Context.
	 * @return mixed
	 */
	public function render_tag( $tag, $post, $context = 'text' ) {
		if ( ! is_string( $tag ) || ! preg_match( '/^tf_form_(count|left):([^{}]+)$/', $tag, $m ) ) {
			return $tag;
		}
		return self::tag_value( $m[1], $m[2], $post );
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
		if ( ! is_string( $content ) || false === strpos( $content, '{tf_form_' ) ) {
			return $content;
		}
		return preg_replace_callback(
			'/\{tf_form_(count|left):([^{}]+)\}/',
			function ( $m ) use ( $post ) {
				return self::tag_value( $m[1], $m[2], $post );
			},
			$content
		);
	}

	/**
	 * A tag's value. "abc123" reads the form on the current post; "abc123:42" on post 42.
	 *
	 * @param string $what count|left.
	 * @param string $arg  Form id, optionally ":post id".
	 * @param mixed  $post Current post.
	 * @return string
	 */
	private static function tag_value( $what, $arg, $post ) {
		$parts    = explode( ':', trim( (string) $arg ) );
		$form_id  = preg_replace( '/[^a-z0-9]/i', '', $parts[0] );
		$post_id  = isset( $parts[1] ) ? absint( $parts[1] ) : ( is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) get_the_ID() );
		$settings = null;
		if ( $form_id && $post_id && class_exists( '\Bricks\Helpers' ) ) {
			$found    = \Bricks\Helpers::get_element_settings( $post_id, $form_id );
			$settings = is_array( $found ) ? $found : null;
		}
		$n = self::count( $form_id, $settings );
		if ( 'count' === $what ) {
			return (string) $n;
		}
		$max = is_array( $settings ) && isset( $settings['tfMax'] ) && is_numeric( $settings['tfMax'] ) ? (int) $settings['tfMax'] : 0;
		return $max > 0 ? (string) max( 0, $max - $n ) : '';
	}

	// ------------------------------------------------------------------
	// Action: update meta
	// ------------------------------------------------------------------

	/**
	 * Run "Update meta (TokenForge)".
	 *
	 * @param object $form The builder's form handler.
	 * @return void
	 */
	public function run_meta( $form ) {
		$settings = $form->get_settings();
		$rows     = isset( $settings['tfMetaRows'] ) && is_array( $settings['tfMetaRows'] ) ? $settings['tfMetaRows'] : array();
		$user     = isset( $settings['tfMetaTarget'] ) && 'user' === $settings['tfMetaTarget'];
		if ( $user ) {
			$id = get_current_user_id();
			if ( ! $id ) {
				self::error( $form, 'tf-meta', __( 'Please sign in first.', 'tokenforge' ) );
				return;
			}
		} else {
			$id = self::meta_post( $form, $settings );
			if ( ! $id ) {
				self::error( $form, 'tf-meta', __( 'This form cannot save its answers here.', 'tokenforge' ) );
				return;
			}
		}
		foreach ( $rows as $row ) {
			$key = isset( $row['key'] ) ? trim( wp_strip_all_tags( (string) $row['key'] ) ) : '';
			if ( '' === $key || ! self::key_allowed( $key, $user ? 'user' : 'post' ) ) {
				continue;
			}
			$value = isset( $row['value'] ) ? self::render( $form, (string) $row['value'] ) : '';
			if ( '' === trim( $value ) && ! empty( $row['skipEmpty'] ) ) {
				continue;
			}
			$mode = isset( $row['mode'] ) && in_array( $row['mode'], array( 'add', 'subtract', 'append', 'remove' ), true ) ? $row['mode'] : 'replace';
			$old  = $user ? get_user_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
			self::write_meta( $user ? 'user' : 'post', $id, $key, self::new_value( $mode, $old, $value ) );
		}
	}

	/**
	 * The post an "Update meta" action may write to (0 when none).
	 *
	 * @param object $form     The builder's form handler.
	 * @param array  $settings Form settings.
	 * @return int
	 */
	private static function meta_post( $form, $settings ) {
		$own = self::own_post( $form );
		$set = isset( $settings['tfMetaPost'] ) ? trim( self::render( $form, (string) $settings['tfMetaPost'] ) ) : '';
		$id  = '' !== $set ? absint( $set ) : $own;
		if ( ! $id || ! get_post( $id ) ) {
			return 0;
		}
		$anyone = isset( $settings['tfMetaWho'] ) && 'anyone' === $settings['tfMetaWho'];
		if ( $anyone ) {
			// Anyone writes only to the public page the form is on, never a post they name.
			return $own && $id === $own && is_post_publicly_viewable( $id ) ? $id : 0;
		}
		return current_user_can( 'edit_post', $id ) ? $id : 0;
	}

	/**
	 * The post a form verifiably belongs to (0 when unknown): a loop post the
	 * builder signed, or the post whose own content holds the form. The posted
	 * postId alone is not trusted: a form in a template is found for any postId.
	 *
	 * @param object $form The builder's form handler.
	 * @return int
	 */
	private static function own_post( $form ) {
		$loop = method_exists( $form, 'get_verified_post_target' ) ? (int) $form->get_verified_post_target() : 0;
		if ( $loop ) {
			return $loop;
		}
		$post   = (int) $form->get_post_id();
		$source = null;
		if ( property_exists( $form, 'settings_source_id' ) ) {
			$read   = function () {
				return $this->settings_source_id; // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- the form handler's own property
			};
			$bound  = \Closure::bind( $read, $form, get_class( $form ) );
			$source = $bound ? $bound() : null;
		}
		return $post && (int) $source === $post ? $post : 0;
	}

	/**
	 * May a form write this meta key?
	 *
	 * @param string $key  Meta key.
	 * @param string $type post|user.
	 * @return bool
	 */
	public static function key_allowed( $key, $type ) {
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,190}$/', $key ) || is_protected_meta( $key, $type ) ) {
			return false;
		}
		if ( 'user' === $type ) {
			global $wpdb;
			$prefix = $wpdb ? $wpdb->get_blog_prefix() : 'wp_';
			if ( 0 === strpos( $key, $prefix ) || 0 === strpos( $key, $wpdb ? $wpdb->base_prefix : 'wp_' ) || preg_match( '/capabilities|user_level|session_tokens|^use_ssl$|^locale$|^rich_editing$|^syntax_highlighting$|^admin_color$|^show_admin_bar|^dismissed_wp_pointers$|^default_password_nag$/', $key ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The value to store.
	 *
	 * @param string $mode  replace|add|subtract|append|remove.
	 * @param mixed  $old   Stored value.
	 * @param string $value New value.
	 * @return mixed
	 */
	public static function new_value( $mode, $old, $value ) {
		switch ( $mode ) {
			case 'add':
			case 'subtract':
				$n = Form_Extras::to_number( is_scalar( $old ) ? (string) $old : '' );
				$d = Form_Extras::to_number( $value );
				$r = 'add' === $mode ? $n + $d : $n - $d;
				return (string) ( floor( $r ) === $r ? (int) $r : round( $r, 6 ) );
			case 'append':
			case 'remove':
				$list = is_array( $old ) ? array_values( $old ) : ( '' === (string) $old ? array() : array( (string) $old ) );
				if ( 'append' === $mode ) {
					if ( '' !== $value && ! in_array( $value, $list, true ) ) {
						$list[] = $value;
					}
					return array_slice( $list, -1000 ); // a list anyone can add to keeps its newest 1000
				}
				return array_values(
					array_filter(
						$list,
						function ( $v ) use ( $value ) {
							return (string) $v !== $value;
						}
					)
				);
		}
		return $value;
	}

	/**
	 * Write a value (through ACF when it is active, so its field types apply).
	 *
	 * @param string $type  post|user.
	 * @param int    $id    Post or user id.
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private static function write_meta( $type, $id, $key, $value ) {
		if ( function_exists( 'update_field' ) && function_exists( 'acf_get_field' ) && acf_get_field( $key ) ) {
			update_field( $key, $value, 'user' === $type ? 'user_' . $id : $id );
			return;
		}
		if ( 'user' === $type ) {
			update_user_meta( $id, $key, $value );
		} else {
			update_post_meta( $id, $key, $value );
		}
	}

	/**
	 * Fill {{field ID}} placeholders and dynamic data (the builder's own way).
	 *
	 * @param object $form The builder's form handler.
	 * @param string $text Text.
	 * @return string
	 */
	private static function render( $form, $text ) {
		$out = method_exists( $form, 'render_data' ) ? $form->render_data( $text ) : $text;
		return is_scalar( $out ) ? wp_strip_all_tags( (string) $out ) : '';
	}

	/**
	 * Report an error the builder's way (it stops the remaining actions).
	 *
	 * @param object $form   The builder's form handler.
	 * @param string $action Action name.
	 * @param string $msg    Message.
	 * @return void
	 */
	private static function error( $form, $action, $msg ) {
		$form->set_result(
			array(
				'action'  => $action,
				'type'    => 'error',
				'message' => $msg,
			)
		);
	}

	// ------------------------------------------------------------------
	// Action: add to cart
	// ------------------------------------------------------------------

	/**
	 * Run "Add to cart (TokenForge)".
	 *
	 * @param object $form The builder's form handler.
	 * @return void
	 */
	public function run_cart( $form ) {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! WC()->cart ) {
			self::error( $form, 'tf-cart', __( 'The cart is not available right now.', 'tokenforge' ) );
			return;
		}
		$settings = $form->get_settings();
		$pid      = absint( self::render( $form, isset( $settings['tfCartProduct'] ) ? (string) $settings['tfCartProduct'] : '' ) );
		$product  = $pid ? wc_get_product( $pid ) : null;
		$parent   = $product && $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $product || ! $parent || 'publish' !== $parent->get_status() || ! $product->is_purchasable() ) {
			self::error( $form, 'tf-cart', __( 'This product cannot be bought right now.', 'tokenforge' ) );
			return;
		}
		$qty_text = isset( $settings['tfCartQty'] ) ? self::render( $form, (string) $settings['tfCartQty'] ) : '';
		$qty      = '' === trim( $qty_text ) ? 1 : (int) floor( Form_Extras::to_number( $qty_text ) );
		$qty      = max( 1, min( 9999, $qty ) );
		$data     = array();
		$fields   = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? array_values( $settings['fields'] ) : array();
		$sent     = (array) $form->get_fields();
		$hidden   = Form_Extras::hidden_fields( $fields, $sent );
		if ( ! empty( $settings['tfCartPrice'] ) ) {
			// A form price needs answers the form itself offers: a product picked
			// from a choice field (not typed), choices from their options, numbers
			// within their limits. Otherwise any product or price could be posted.
			if ( preg_match_all( '/\{\{(\w+)\}\}/', isset( $settings['tfCartProduct'] ) ? (string) $settings['tfCartProduct'] : '', $refs ) ) {
				foreach ( $refs[1] as $ref ) {
					$pf = Form_Extras::find_field( $fields, $ref );
					if ( ! $pf || ! in_array( isset( $pf['type'] ) ? $pf['type'] : '', array( 'select', 'radio' ), true ) ) {
						self::error( $form, 'tf-cart', __( 'With a form price, the product must come from a select or radio field.', 'tokenforge' ) );
						return;
					}
				}
			}
			if ( ! self::answers_valid( $fields, $sent ) ) {
				self::error( $form, 'tf-cart', __( 'Please check your answers and try again.', 'tokenforge' ) );
				return;
			}
			$field = Form_Extras::find_field( $fields, (string) $settings['tfCartPrice'] );
			if ( ! $field || ! Form_Extras::has_calc( $field ) ) {
				self::error( $form, 'tf-cart', __( 'The price field must be a field with Calculate.', 'tokenforge' ) );
				return;
			}
			// Worked out again from the answers: the browser's number is never used.
			$calc  = Form_Extras::calculate( $fields, $sent, $hidden );
			$price = isset( $calc[ $field['id'] ] ) ? Form_Extras::to_number( $calc[ $field['id'] ] ) : 0;
			if ( $price <= 0 || ! is_finite( $price ) ) {
				self::error( $form, 'tf-cart', __( 'This price cannot be used.', 'tokenforge' ) );
				return;
			}
			$data['price'] = round( $price, wc_get_price_decimals() );
		}
		if ( ! empty( $settings['tfCartAnswers'] ) ) {
			$data['answers'] = self::answers( $fields, $sent, $hidden );
		}
		$variation_id = $product->is_type( 'variation' ) ? $product->get_id() : 0;
		$variation    = $variation_id ? $product->get_variation_attributes() : array();
		$product_id   = $variation_id ? $product->get_parent_id() : $product->get_id();
		$added        = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation, $data ? array( self::CART => $data ) : array() );
		if ( ! $added ) {
			$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
			if ( function_exists( 'wc_clear_notices' ) ) {
				wc_clear_notices();
			}
			$first = $notices ? reset( $notices ) : null;
			$text  = is_array( $first ) && isset( $first['notice'] ) ? wp_strip_all_tags( $first['notice'] ) : __( 'This product could not be added to the cart.', 'tokenforge' );
			self::error( $form, 'tf-cart', $text );
			return;
		}
		$go = isset( $settings['tfCartGo'] ) ? $settings['tfCartGo'] : '';
		if ( in_array( $go, array( 'cart', 'checkout' ), true ) ) {
			$form->set_result(
				array(
					'action'           => 'tf-cart',
					'type'             => 'redirect',
					'redirectTo'       => 'cart' === $go ? wc_get_cart_url() : wc_get_checkout_url(),
					'redirectRendered' => true,
				)
			);
		}
	}

	/**
	 * The visitor's answers as label => text (for the cart and the order).
	 *
	 * @param array    $fields Field settings.
	 * @param array    $sent   Submitted values.
	 * @param string[] $hidden Ids of hidden fields.
	 * @return array
	 */
	private static function answers( $fields, $sent, $hidden ) {
		$out = array();
		foreach ( $fields as $f ) {
			$type = isset( $f['type'] ) ? $f['type'] : 'text';
			if ( empty( $f['id'] ) || in_array( $f['id'], $hidden, true ) || in_array( $type, array( 'html', 'hidden', 'password', 'file', 'image', 'gallery', 'rememberme' ), true ) ) {
				continue;
			}
			$v = null;
			foreach ( array( Form_Extras::input_name( $f ), 'form-field-' . $f['id'] ) as $name ) {
				if ( isset( $sent[ $name ] ) ) {
					$v = $sent[ $name ];
					break;
				}
			}
			// A choice shows its label, not its value ("Large", not "20").
			$labels = self::option_labels( $f );
			$list   = is_array( $v ) ? array_map( 'strval', array_filter( $v, 'is_scalar' ) ) : ( is_scalar( $v ) ? array( (string) $v ) : array() );
			$text   = implode(
				', ',
				array_map(
					function ( $one ) use ( $labels ) {
						return isset( $labels[ $one ] ) ? $labels[ $one ] : $one;
					},
					$list
				)
			);
			$text = trim( wp_strip_all_tags( $text ) );
			if ( '' === $text ) {
				continue;
			}
			$label         = isset( $f['label'] ) && '' !== trim( (string) $f['label'] ) ? wp_strip_all_tags( (string) $f['label'] ) : $f['id'];
			$out[ $label ] = mb_substr( $text, 0, 500 );
		}
		return array_slice( $out, 0, 30, true );
	}

	/**
	 * Are a submission's answers ones the form offers? Choices must be among their
	 * options, numbers within their min and max. Calculated fields are skipped
	 * (they are worked out again).
	 *
	 * @param array $fields Field settings.
	 * @param array $sent   Submitted values.
	 * @return bool
	 */
	public static function answers_valid( $fields, $sent ) {
		foreach ( $fields as $f ) {
			if ( empty( $f['id'] ) || Form_Extras::has_calc( $f ) ) {
				continue;
			}
			$v = null;
			foreach ( array( Form_Extras::input_name( $f ), 'form-field-' . $f['id'] ) as $name ) {
				if ( isset( $sent[ $name ] ) ) {
					$v = $sent[ $name ];
					break;
				}
			}
			$list = array_filter(
				array_map( 'strval', is_array( $v ) ? array_filter( $v, 'is_scalar' ) : ( is_scalar( $v ) ? array( $v ) : array() ) ),
				function ( $x ) {
					return '' !== trim( $x );
				}
			);
			if ( ! $list ) {
				continue;
			}
			$type    = isset( $f['type'] ) ? $f['type'] : 'text';
			$allowed = self::allowed_values( $f );
			if ( null !== $allowed ) {
				foreach ( $list as $one ) {
					if ( ! in_array( trim( $one ), $allowed, true ) ) {
						return false;
					}
				}
			}
			if ( 'number' === $type ) {
				foreach ( $list as $one ) {
					if ( ! is_numeric( $one ) ) {
						return false;
					}
					$max = isset( $f['max'] ) && is_numeric( $f['max'] ) ? (float) $f['max'] : ( isset( $f['tfLook'] ) && 'rating' === $f['tfLook'] ? 5.0 : null );
					if ( ( isset( $f['min'] ) && is_numeric( $f['min'] ) && (float) $one < (float) $f['min'] ) || ( null !== $max && (float) $one > $max ) ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * The values a choice field offers (null when it is not a choice field or has no list).
	 *
	 * @param array $field Field settings.
	 * @return string[]|null
	 */
	private static function allowed_values( $field ) {
		if ( ! in_array( isset( $field['type'] ) ? $field['type'] : '', array( 'select', 'radio', 'checkbox' ), true ) ) {
			return null;
		}
		$out   = array();
		$typed = ! empty( $field['options'] ) && ( empty( $field['tfOptFrom'] ) || ! empty( $field['tfOptKeep'] ) ) ? preg_split( '/\r\n|\r|\n/', (string) $field['options'] ) : array();
		foreach ( $typed as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// The value the rendered option carries (see Form_Fields::fill_options() and the builder's "value:label").
			if ( ! empty( $field['valueLabelOptions'] ) ) {
				$out[] = false !== strpos( $line, ':' ) ? trim( strstr( $line, ':', true ) ) : $line;
			} else {
				$out[] = ! empty( $field['tfOptFrom'] ) ? Form_Fields::option_value( $line ) : $line;
			}
		}
		if ( ! empty( $field['tfOptFrom'] ) ) {
			foreach ( array_keys( (array) Form_Fields::content_options( $field ) ) as $value ) {
				$out[] = Form_Fields::option_value( (string) $value );
			}
		}
		return $out ? $out : null;
	}

	/**
	 * A choice field's labels by value (typed value:label lines, or options from content).
	 *
	 * @param array $field Field settings.
	 * @return array
	 */
	private static function option_labels( $field ) {
		if ( ! in_array( isset( $field['type'] ) ? $field['type'] : '', array( 'select', 'radio', 'checkbox' ), true ) ) {
			return array();
		}
		$out = ! empty( $field['tfOptFrom'] ) ? (array) Form_Fields::content_options( $field ) : array();
		if ( ! empty( $field['valueLabelOptions'] ) && ! empty( $field['options'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $field['options'] ) as $line ) {
				$parts = explode( ':', $line, 2 );
				if ( 2 === count( $parts ) ) {
					$out[ trim( $parts[0] ) ] = wp_strip_all_tags( trim( $parts[1] ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Cart items added by a form keep the form's price.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return void
	 */
	public static function cart_prices( $cart ) {
		if ( ! is_object( $cart ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item[ self::CART ]['price'], $item['data'] ) && is_object( $item['data'] ) ) {
				$item['data']->set_price( (float) $item[ self::CART ]['price'] );
			}
		}
	}

	/**
	 * The form's price also when the cart is read back from the session (the
	 * mini cart shows item prices without working out the totals again).
	 *
	 * @param array $item   Cart item.
	 * @param array $values Stored values.
	 * @return array
	 */
	public static function session_price( $item, $values ) {
		if ( isset( $values[ self::CART ]['price'], $item['data'] ) && is_object( $item['data'] ) ) {
			$item['data']->set_price( (float) $values[ self::CART ]['price'] );
		}
		return $item;
	}

	/**
	 * The answers under the item in the cart and the checkout.
	 *
	 * @param array $data Item data rows.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function cart_item_data( $data, $item ) {
		if ( ! empty( $item[ self::CART ]['answers'] ) && is_array( $item[ self::CART ]['answers'] ) ) {
			foreach ( $item[ self::CART ]['answers'] as $label => $text ) {
				$data[] = array(
					'key'   => (string) $label,
					'value' => (string) $text,
				);
			}
		}
		return $data;
	}

	/**
	 * The answers on the order item.
	 *
	 * @param \WC_Order_Item_Product $order_item Order item.
	 * @param string                 $key        Cart item key.
	 * @param array                  $item       Cart item.
	 * @return void
	 */
	public static function order_item_meta( $order_item, $key, $item ) {
		if ( ! empty( $item[ self::CART ]['answers'] ) && is_array( $item[ self::CART ]['answers'] ) ) {
			foreach ( $item[ self::CART ]['answers'] as $label => $text ) {
				$order_item->add_meta_data( (string) $label, (string) $text );
			}
		}
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
}
