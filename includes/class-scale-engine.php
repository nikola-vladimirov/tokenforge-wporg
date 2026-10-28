<?php
/**
 * Fluid scale engine: generates clamp()-based typography and spacing scales,
 * plus the grid variable set, from a small settings object.
 *
 * Original implementation. The fluid formula interpolates a value linearly
 * between a minimum viewport and a maximum viewport, then clamps it.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless generators for fluid scales + grids.
 */
class Scale_Engine {

	const OPTION = 'tokenforge_scales';

	/** T-shirt step names, small → large. */
	const STEPS = array( 'xs', 's', 'm', 'l', 'xl', 'xxl' );

	/**
	 * Default scale settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'scales', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'min_vw'        => 375,
			'max_vw'        => 1280,
			// Typography: base size (px) at min/max viewport, and the modular ratio.
			'text_base_min' => 16,
			'text_base_max' => 20,
			'text_ratio_min' => 1.2,
			'text_ratio_max' => 1.25,
			// Headings (--h1…--h6, h4 = base): base size (px) at min/max viewport + ratios.
			'heading_base_min'  => 18,
			'heading_base_max'  => 20,
			'heading_ratio_min' => 1.2,
			'heading_ratio_max' => 1.333,
			// Spacing: base size (px) at min/max viewport, and the ratio (desktop / mobile).
			'space_base_min' => 16,
			'space_base_max' => 24,
			'space_ratio'    => 1.5,
			'space_ratio_min' => 1.333,
			// Section spacing = space scale × multiplier (mobile / desktop).
			'section_mult_min' => 2,
			'section_mult_max' => 3,
			// Per-size overrides, one per line: "h5-min=13", "text-s-max=15" (px).
			'overrides'      => '',
			// Grid.
			'grid_columns'   => 6,
			'grid_gap'       => 24,
			'content_width'  => 1140,
			'content_gutter' => 24,
			'enabled'        => true,
			// "Transition" variables: --space-l-to-m, --text-xl-to-l, --h1-to-h3 …
			// (the larger step on desktop shrinking to the smaller one on mobile).
			'transition_vars' => true,
			// Auto grids (--grid-auto-2 … 12).
			'auto_grid'                => true,
			'auto_grid_aggressiveness' => 0.7,
			'auto_grid_flow'           => 'auto-fit', // auto-fit | auto-fill
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Save settings (numeric-sanitised).
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$d     = self::defaults();
		$clean = array();
		foreach ( $d as $k => $v ) {
			if ( is_bool( $v ) ) {
				$clean[ $k ] = ! empty( $in[ $k ] );
			} elseif ( 'auto_grid_flow' === $k ) {
				$clean[ $k ] = ( isset( $in[ $k ] ) && 'auto-fill' === $in[ $k ] ) ? 'auto-fill' : 'auto-fit';
			} elseif ( 'overrides' === $k ) {
				$clean[ $k ] = isset( $in[ $k ] ) ? preg_replace( '/[^a-z0-9\-=.\s]/i', '', (string) $in[ $k ] ) : '';
			} elseif ( isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ) {
				$clean[ $k ] = 0 + $in[ $k ];
			} else {
				$clean[ $k ] = $v;
			}
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * Build a fluid clamp() expression between two px values across the viewport.
	 *
	 * @param float $min_px Value at the min viewport.
	 * @param float $max_px Value at the max viewport.
	 * @param float $min_vw Min viewport (px).
	 * @param float $max_vw Max viewport (px).
	 * @return string
	 */
	public static function fluid( $min_px, $max_px, $min_vw, $max_vw ) {
		$r       = Defaults::root_px();
		$min_rem = $min_px / $r;
		$max_rem = $max_px / $r;
		if ( $max_vw <= $min_vw || $max_px === $min_px ) {
			return self::rem( $min_rem );
		}
		$slope        = ( $max_px - $min_px ) / ( $max_vw - $min_vw );
		$intercept_px = $min_px - $slope * $min_vw;
		$intercept    = round( $intercept_px / $r, 6 );
		$vw           = round( $slope * 100, 6 );
		$lo           = min( $min_rem, $max_rem );
		$hi           = max( $min_rem, $max_rem );
		return sprintf( 'clamp(%srem, %srem + %svw, %srem)', self::n( $lo ), self::n( $intercept ), self::n( $vw ), self::n( $hi ) );
	}

	/**
	 * All generated scale variables as [{name, value, group}].
	 *
	 * @return array
	 */
	public static function variables() {
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return array();
		}
		$out = array();
		$mid = array_search( 'm', self::STEPS, true ); // "m" is the base step (as in ACSS)
		$ov  = self::overrides();
		$pick = function ( $name, $min, $max ) use ( $ov ) {
			return array(
				isset( $ov[ $name ]['min'] ) ? $ov[ $name ]['min'] : $min,
				isset( $ov[ $name ]['max'] ) ? $ov[ $name ]['max'] : $max,
			);
		};

		// [min, max] px per step, kept for the transition variables below.
		$sizes = array( 'text' => array(), 'h' => array(), 'space' => array(), 'section-space' => array() );

		// Typography: text sizes.
		foreach ( self::STEPS as $i => $step ) {
			$exp   = $i - $mid;
			list( $min, $max ) = $pick( 'text-' . $step, $s['text_base_min'] * pow( $s['text_ratio_min'], $exp ), $s['text_base_max'] * pow( $s['text_ratio_max'], $exp ) );
			$sizes['text'][ $step ] = array( $min, $max );
			$out[] = array( 'name' => 'text-' . $step, 'value' => self::fluid( $min, $max, $s['min_vw'], $s['max_vw'] ), 'group' => 'typography' );
		}
		// Typography: heading sizes (h4 = base, h1 largest, h6 smallest).
		foreach ( array( 1 => 3, 2 => 2, 3 => 1, 4 => 0, 5 => -1, 6 => -2 ) as $lvl => $exp ) {
			list( $min, $max ) = $pick( 'h' . $lvl, $s['heading_base_min'] * pow( $s['heading_ratio_min'], $exp ), $s['heading_base_max'] * pow( $s['heading_ratio_max'], $exp ) );
			$sizes['h'][ $lvl ] = array( $min, $max );
			$out[] = array( 'name' => 'h' . $lvl, 'value' => self::fluid( $min, $max, $s['min_vw'], $s['max_vw'] ), 'group' => 'typography' );
		}
		// Spacing (+ section spacing = space × multiplier).
		foreach ( self::STEPS as $i => $step ) {
			$exp = $i - $mid;
			list( $min, $max ) = $pick( 'space-' . $step, $s['space_base_min'] * pow( $s['space_ratio_min'], $exp ), $s['space_base_max'] * pow( $s['space_ratio'], $exp ) );
			$sizes['space'][ $step ] = array( $min, $max );
			$out[] = array( 'name' => 'space-' . $step, 'value' => self::fluid( $min, $max, $s['min_vw'], $s['max_vw'] ), 'group' => 'space' );
			list( $smin, $smax ) = $pick( 'section-space-' . $step, $min * $s['section_mult_min'], $max * $s['section_mult_max'] );
			$sizes['section-space'][ $step ] = array( $smin, $smax );
			$out[] = array( 'name' => 'section-space-' . $step, 'value' => self::fluid( $smin, $smax, $s['min_vw'], $s['max_vw'] ), 'group' => 'space' );
		}

		// Transition variables: from the larger step's desktop size down to the
		// smaller step's mobile size (e.g. --section-space-l-to-m).
		if ( ! empty( $s['transition_vars'] ) ) {
			foreach ( $sizes as $scale => $steps ) {
				$keys  = array_keys( $steps ); // small → large for t-shirt steps; h1 → h6 for headings
				$group = 'h' === $scale || 'text' === $scale ? 'typography' : 'space';
				foreach ( $keys as $a ) {
					foreach ( $keys as $b ) {
						$bigger = 'h' === $scale ? $a < $b : array_search( $a, self::STEPS, true ) > array_search( $b, self::STEPS, true );
						if ( ! $bigger ) {
							continue;
						}
						$name  = 'h' === $scale ? "h$a-to-h$b" : "$scale-$a-to-$b";
						$out[] = array( 'name' => $name, 'value' => self::fluid( $steps[ $b ][0], $steps[ $a ][1], $s['min_vw'], $s['max_vw'] ), 'group' => $group );
					}
				}
			}
		}
		// Grid templates: equal columns, ratios, and auto-fit grids that never
		// exceed N columns and only drop columns once a column would get narrower
		// than `aggressiveness` × its full-width size.
		$cols = max( 12, (int) $s['grid_columns'] );
		for ( $c = 1; $c <= $cols; $c++ ) {
			$out[] = array( 'name' => 'grid-' . $c, 'value' => 'repeat(' . $c . ', minmax(0, 1fr))', 'group' => 'other' );
		}
		foreach ( array( '1-2', '2-1', '1-3', '3-1', '2-3', '3-2' ) as $ratio ) {
			list( $a, $b ) = explode( '-', $ratio );
			$out[]         = array( 'name' => 'grid-' . $ratio, 'value' => "minmax(0, {$a}fr) minmax(0, {$b}fr)", 'group' => 'other' );
		}
		if ( ! empty( $s['auto_grid'] ) ) {
			$out[] = array( 'name' => 'auto-grid-aggressiveness', 'value' => (string) (float) $s['auto_grid_aggressiveness'], 'group' => 'other' );
			$flow  = 'auto-fill' === $s['auto_grid_flow'] ? 'auto-fill' : 'auto-fit';
			for ( $c = 2; $c <= 12; $c++ ) {
				$out[] = array( 'name' => 'grid-auto-' . $c, 'value' => self::auto_columns( $c, $flow ), 'group' => 'other' );
			}
		}
		$out[] = array( 'name' => 'grid-gap', 'value' => self::rem( $s['grid_gap'] / Defaults::root_px() ), 'group' => 'space' );
		$out[] = array( 'name' => 'content-gutter', 'value' => self::rem( $s['content_gutter'] / Defaults::root_px() ), 'group' => 'space' );

		foreach ( $out as &$t ) {
			$t['enabled'] = true;
		}
		return $out;
	}

	/**
	 * Parsed size overrides: name => [ 'min' => px, 'max' => px ].
	 * Lines look like "h5-min=13" or "text-s-max=15" (ACSS-style keys).
	 *
	 * @return array
	 */
	public static function overrides() {
		$s   = self::settings();
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $s['overrides'] ) as $line ) {
			if ( preg_match( '/^\s*([a-z0-9\-]+)-(min|max)\s*=\s*([0-9.]+)\s*$/i', $line, $m ) ) {
				$out[ strtolower( $m[1] ) ][ strtolower( $m[2] ) ] = (float) $m[3];
			}
		}
		return $out;
	}

	/**
	 * Grid / layout utility CSS generated from the scale.
	 *
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function grid_css() {
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return array( 'css' => '', 'classes' => array() );
		}
		$css     = array();
		$classes = array();

		// Equal-column grids 1 to 12.
		for ( $c = 1; $c <= 12; $c++ ) {
			$css[]     = ".grid-$c{display:grid;grid-template-columns:repeat($c,minmax(0,1fr));gap:var(--grid-gap)}";
			$classes[] = "grid-$c";
		}
		// Asymmetric ratio grids.
		$ratios = array( '1-2' => '1fr 2fr', '2-1' => '2fr 1fr', '1-3' => '1fr 3fr', '3-1' => '3fr 1fr', '2-3' => '2fr 3fr', '3-2' => '3fr 2fr' );
		foreach ( $ratios as $name => $cols ) {
			$css[]     = ".grid-$name{display:grid;grid-template-columns:$cols;gap:var(--grid-gap)}";
			$classes[] = "grid-$name";
		}
		// Auto grid: responsive columns that fit a min width (aliased .grid-auto).
		$css[]     = '.auto-grid,.grid-auto{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,var(--auto-grid-min,16rem)),1fr));gap:var(--grid-gap)}';
		$classes[] = 'auto-grid';
		$classes[] = 'grid-auto';
		// Auto grid classes .grid--auto-2 … 12: the --grid-auto-N columns as a class.
		// One shared rule reads --column-count, so .grid--auto-fill / -fit can switch
		// the flow on one element. The flow is set on every grid, so a nested grid
		// never inherits its parent's. Every rule is one line for kept_rules().
		if ( ! empty( $s['auto_grid'] ) ) {
			$flow = 'auto-fill' === $s['auto_grid_flow'] ? 'auto-fill' : 'auto-fit';
			$sels = array();
			for ( $c = 2; $c <= 12; $c++ ) {
				$sels[]    = ".grid--auto-$c";
				$classes[] = "grid--auto-$c";
			}
			$css[] = implode( ',', $sels ) . "{--grid-auto-flow:$flow;display:grid;grid-template-columns:" . self::auto_columns( 'var(--column-count)', 'var(--grid-auto-flow)' ) . ';gap:var(--grid-gap);inline-size:100%}';
			for ( $c = 2; $c <= 12; $c++ ) {
				$css[] = ".grid--auto-$c{--column-count:$c}";
			}
			$css[]     = '[class*="grid--auto-"]>*{min-width:0;max-width:100%;overflow-wrap:break-word}';
			$css[]     = '.grid--auto-fill{--grid-auto-flow:auto-fill}';
			$css[]     = '.grid--auto-fit{--grid-auto-flow:auto-fit}';
			$css[]     = '.grid--auto-rows{grid-auto-rows:minmax(min-content,1fr)}';
			$classes[] = 'grid--auto-fill';
			$classes[] = 'grid--auto-fit';
			$classes[] = 'grid--auto-rows';
		}
		// Variable grid: column count driven by a variable you can change per breakpoint.
		$css[]     = '.variable-grid{display:grid;grid-template-columns:repeat(var(--variable-grid-count,3),minmax(0,1fr));gap:var(--grid-gap)}';
		$classes[] = 'variable-grid';
		// (The content grid lives in Components → layout.)
		return array( 'css' => "\n/* --- TokenForge grids --- */\n" . implode( "\n", $css ) . "\n", 'classes' => $classes );
	}

	/**
	 * Auto grid columns: never more than $n, and a column only drops once it
	 * would get narrower than aggressiveness × its full-width size.
	 *
	 * @param int|string $n    Column count, or a var() for it.
	 * @param string     $flow auto-fit | auto-fill, or a var() for it.
	 * @return string
	 */
	private static function auto_columns( $n, $flow ) {
		$gaps  = is_int( $n ) ? '(' . ( $n - 1 ) . ' * var(--grid-gap))' : "(($n - 1) * var(--grid-gap))";
		$ideal = "calc((var(--content-width) - $gaps) / $n * var(--auto-grid-aggressiveness))";
		$floor = "calc((100% - $gaps) / $n)";
		return "repeat($flow, minmax(min(100%, max($ideal, $floor)), 1fr))";
	}

	/**
	 * Format a rem value, trimming trailing zeros.
	 *
	 * @param float $rem Value in rem.
	 * @return string
	 */
	private static function rem( $rem ) {
		return self::n( round( $rem, 4 ) ) . 'rem';
	}

	/**
	 * Number formatter without trailing zeros.
	 *
	 * @param float $v Value.
	 * @return string
	 */
	private static function n( $v ) {
		return rtrim( rtrim( sprintf( '%.6f', $v ), '0' ), '.' );
	}
}
