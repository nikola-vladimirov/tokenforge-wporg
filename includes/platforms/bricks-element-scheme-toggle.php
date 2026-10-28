<?php
/**
 * Bricks element: light/dark scheme toggle.
 *
 * A native, drag-and-drop version of the [tokenforge_scheme_toggle] shortcode.
 * Behaviour (remembered choice, no flash on load) comes from TokenForge's
 * front-end script; this element only renders the button and exposes styling
 * controls. Loaded only when Bricks is active.
 *
 * @package TokenForge
 */

namespace TokenForge\Platforms;

use TokenForge\Scheme;

defined( 'ABSPATH' ) || exit;

/**
 * Scheme toggle element.
 */
class Element_Scheme_Toggle extends \Bricks\Element {

	/** @var string */
	public $category = 'TokenForge';

	/** @var string */
	public $name = 'tokenforge-scheme-toggle';

	/** @var string */
	public $icon = 'ti-shine';

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Light/Dark Toggle', 'tokenforge' );
	}

	/**
	 * Controls.
	 *
	 * @return void
	 */
	public function set_controls() {
		$this->controls['info'] = array(
			'tab'     => 'content',
			'type'    => 'info',
			'content' => esc_html__( 'Switches the page between light and dark (remembered per visitor). Needs TokenForge → Colors → Dark mode "Auto" and the visitor switcher enabled.', 'tokenforge' ),
		);
		$this->controls['ariaLabel'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Accessible label', 'tokenforge' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'Toggle dark mode', 'tokenforge' ),
		);
		$this->controls['size'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Size', 'tokenforge' ),
			'type'        => 'number',
			'units'       => true,
			'placeholder' => '2.5em',
			'css'         => array(
				array( 'property' => 'inline-size' ),
				array( 'property' => 'block-size' ),
			),
		);
		$this->controls['iconSize'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Icon size', 'tokenforge' ),
			'type'        => 'number',
			'units'       => true,
			'placeholder' => '1.25em',
			'css'         => array(
				array( 'selector' => 'svg', 'property' => 'inline-size' ),
				array( 'selector' => 'svg', 'property' => 'block-size' ),
			),
		);
		$this->controls['iconColor'] = array(
			'tab'   => 'content',
			'label' => esc_html__( 'Icon color', 'tokenforge' ),
			'type'  => 'color',
			'css'   => array( array( 'property' => 'color' ) ),
		);
		$this->controls['bg'] = array(
			'tab'   => 'content',
			'label' => esc_html__( 'Background', 'tokenforge' ),
			'type'  => 'color',
			'css'   => array( array( 'property' => 'background-color' ) ),
		);
		$this->controls['border'] = array(
			'tab'   => 'content',
			'label' => esc_html__( 'Border', 'tokenforge' ),
			'type'  => 'border',
			'css'   => array( array( 'property' => 'border' ) ),
		);
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public function render() {
		// Switcher off: a hint in the builder, nothing on the live site (Bricks'
		// placeholder helper prints nothing on the front end).
		if ( ! Scheme::switcher_enabled() ) {
			$this->render_element_placeholder(
				array(
					'title'       => esc_html__( 'Light/Dark toggle is off', 'tokenforge' ),
					'description' => esc_html__( 'Enable it in TokenForge → Colors (Dark mode: Auto + Visitor light/dark switcher).', 'tokenforge' ),
				)
			);
			return;
		}

		$label = ! empty( $this->settings['ariaLabel'] ) ? $this->settings['ariaLabel'] : __( 'Toggle dark mode', 'tokenforge' );
		$this->set_attribute( '_root', 'class', 'scheme-toggle' );
		$this->set_attribute( '_root', 'type', 'button' );
		$this->set_attribute( '_root', 'aria-label', esc_attr( $label ) );

		echo '<button ' . $this->render_attributes( '_root' ) . '>' . Scheme::toggle_icons() . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
