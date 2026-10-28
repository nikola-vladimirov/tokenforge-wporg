<?php
/**
 * Recipes: type "?name" in a CSS editor (Bricks element CSS) or
 * "?name;" in an input, and it expands into a ready-made snippet (auto grid,
 * clickable parent, breakpoints, a colour with editable alpha …).
 *
 * The built-in recipes are written for TokenForge (names match the
 * Automatic.css recipes so muscle memory carries over); add your own in the
 * dashboard. "%root%" is the element's own selector in Bricks. "{{cursor}}" marks where the caret lands after expanding.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Recipe library.
 */
class Recipes {

	const OPTION = 'tokenforge_recipes';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'recipes', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'enabled' => true,   // expand ?recipes in the builder
			'inputs'  => true,   // also in builder text inputs (value recipes like ?primary-clr;)
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
		// This build has no custom recipes: drop any code an older version stored.
		unset( $s['custom'] );
		return Modules::apply( 'recipes', $s );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		update_option(
			self::OPTION,
			array(
				'enabled' => ! empty( $in['enabled'] ),
				'inputs'  => ! empty( $in['inputs'] ),
			),
			false
		);
	}

	/**
	 * A recipe name: lowercase letters, digits and dashes.
	 *
	 * @param string $n Raw name.
	 * @return string
	 */
	public static function name( $n ) {
		return trim( preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( ltrim( trim( (string) $n ), '?' ) ) ), '-' );
	}

	/**
	 * Built-in recipes: name => [ category, code, scss-variant|null, inline ].
	 *
	 * @return array
	 */
	public static function builtin() {
		$r = array();

		// ---- Colours: a colour with its alpha ready to edit.
		$shades = array( '' => '', 'ultra-light' => '-ultra-light', 'light' => '-light', 'semi-light' => '-semi-light', 'semi-dark' => '-semi-dark', 'dark' => '-dark', 'ultra-dark' => '-ultra-dark', 'hover' => '-hover' );
		foreach ( Components::FAMILIES as $fam ) {
			foreach ( $shades as $sfx ) {
				$r[ $fam . $sfx . '-clr' ] = array( 'Color', 'oklch(from var(--' . $fam . $sfx . ') l c h / 1)', null, true );
			}
		}

		// ---- Grids.
		$r['auto-grid'] = array( 'Grid', "/* Auto grid: starts at --column-count columns, stacks as space runs out */\n%root% {\n\t--column-count: 4; /* columns on wide screens */\n\t--auto-grid-aggressiveness: 0.7; /* lower = columns hold on longer */\n\t--min: calc((var(--content-width) - (var(--column-count) - 1) * var(--grid-gap)) / var(--column-count) * var(--auto-grid-aggressiveness));\n\t--min-formula: min(100%, max(var(--min), (100% - (var(--column-count) - 1) * var(--grid-gap)) / var(--column-count)));\n\t--grid-template-columns: repeat(auto-fit, minmax(var(--min-formula), 1fr));\n\t/* even columns only: repeat(auto-fit, minmax(var(--min-formula), 1fr) minmax(var(--min-formula), 1fr)) */\n\tdisplay: grid !important;\n\tgrid-template-columns: var(--grid-template-columns);\n\tgap: var(--grid-gap);\n}\n%root% > * {\n\tmin-width: 0;\n\tmax-width: 100%;\n\toverflow-wrap: break-word;\n}{{cursor}}", "@include auto-grid(4{{cursor}});", false );
		$r['auto-grid-deprecated'] = $r['auto-grid'];
		$r['variable-grid'] = array( 'Grid', "/* Variable grid: items keep --min wide and fill the row */\n%root% {\n\t--min: calc(var(--content-width) / 2 - var(--space-xxl));\n\tdisplay: grid !important;\n\tgrid-template-columns: repeat(auto-fit, minmax(min(var(--min), 100%), 1fr));\n\tgap: var(--gap, var(--grid-gap));\n}{{cursor}}", null, false );
		for ( $i = 1; $i <= 12; $i++ ) {
			$r[ "grid-$i" ] = array( 'Grid', "display: grid;\ngrid-template-columns: var(--grid-$i);\ngrid-template-rows: 1fr;{{cursor}}", null, false );
		}
		foreach ( array( '1-2', '1-3', '2-1', '2-3', '3-1', '3-2' ) as $ratio ) {
			$r[ "grid-$ratio" ] = array( 'Grid', "display: grid;\ngrid-template-columns: var(--grid-$ratio);\ngrid-template-rows: 1fr;{{cursor}}", null, false );
		}
		$r['flex-row']    = array( 'Grid', "display: flex;\nflex-direction: row;{{cursor}}", null, false );
		$r['flex-column'] = array( 'Grid', "display: flex;\nflex-direction: column;{{cursor}}", null, false );
		$r['flex-grid']   = array( 'Grid', "/* Flex grid: wraps --columns per row; a short last row stays centred */\n%root% {\n\t--columns: 3;\n\t--gap: var(--grid-gap);\n\tdisplay: flex;\n\tflex-wrap: wrap;\n\tjustify-content: center;\n\tgap: var(--gap);\n}\n%root% > * {\n\tflex: 0 1 calc((100% - (var(--columns) - 1) * var(--gap)) / var(--columns));\n\tmin-width: 0;\n}{{cursor}}", null, false );
		$r['masonry']     = array( 'Grid', "/* Masonry with CSS columns (items flow top to bottom, then across) */\n%root% {\n\t--masonry-min: 260px;\n\tcolumns: auto var(--masonry-min);\n\tcolumn-gap: var(--grid-gap);\n\tdisplay: block !important;\n}\n%root% > * {\n\tbreak-inside: avoid;\n\tmargin-block-end: var(--grid-gap);\n}{{cursor}}", null, false );
		$r['content-grid'] = array( 'Grid', "/* Content grid: children in the content column; break out with grid-column: feature | feature-max | full */\n%root% {\n\t--feature-width: 50px;\n\t--feature-max-width: 100px;\n\t--full: minmax(var(--gutter), 1fr);\n\tdisplay: grid !important;\n\tcolumn-gap: 0 !important;\n\tpadding-inline: 0;\n\tgrid-template-columns:\n\t\t[full-start] var(--full)\n\t\t[feature-max-start] minmax(0, var(--feature-max-width))\n\t\t[feature-start] minmax(0, var(--feature-width))\n\t\t[content-start] min(var(--content-width), 100% - var(--gutter) * 2)\n\t\t[content-end] minmax(0, var(--feature-width))\n\t\t[feature-end] minmax(0, var(--feature-max-width))\n\t\t[feature-max-end] var(--full)\n\t\t[full-end];\n}\n%root% > :where(:not(.content--feature, .content--feature-max, .content--full, .content--full-safe)) {\n\tgrid-column: content;\n\tinline-size: 100%;\n}\n%root% > .is-bg {\n\tgrid-column: full;\n}{{cursor}}", "@include content-grid;{{cursor}}", false );

		// ---- Text.
		$r['ipsum-short'] = array( 'Text', 'Placeholder copy: this paragraph stands in for the real text while the design takes shape. It is long enough to show how a few lines wrap, and it will be replaced once the final wording is ready.', null, true );
		$r['script']      = array( 'Code', "<script type=\"module\">\n\t{{cursor}}\n</script>", null, false );

		// ---- Breakpoints and container queries.
		foreach ( Scss::breakpoints() as $bp => $px ) {
			$r[ "breakpoint-$bp" ]    = array( 'Media queries', "@media (max-width: {$px}px) {\n\t%root% {\n\t\t{{cursor}}\n\t}\n}", "@include breakpoint($bp) {\n\t{{cursor}}\n}", false );
			$r[ "breakpoint-up-$bp" ] = array( 'Media queries', "@media (min-width: {$px}px) {\n\t%root% {\n\t\t{{cursor}}\n\t}\n}", "@include breakpoint-up($bp) {\n\t{{cursor}}\n}", false );
		}
		$r['card-container'] = array( 'Media queries', "/* When the nearest card container is wide enough */\n@container card (inline-size > {{cursor}}480px) {\n\t%root% {\n\t\t\n\t}\n}", "@include card-container(\"inline-size > {{cursor}}480px\") {\n\t\n}", false );
		$r['container']      = array( 'Media queries', "/* Container query (the parent needs container-type: inline-size) */\n@container (inline-size > {{cursor}}480px) {\n\t%root% {\n\t\t\n\t}\n}", "@include container(\"inline-size > {{cursor}}480px\") {\n\t\n}", false );

		// ---- Interaction.
		$r['clickable-parent'] = array( 'Interaction', "/* Clickable parent: the link's click area covers this element */\n%root% {\n\tposition: relative;\n}\n%root% a {\n\tposition: static;\n}\n/* one link per card: point this at it if there are several */\n%root% a::after {\n\tcontent: \"\";\n\tposition: absolute;\n\tinset: 0;\n\tz-index: 1;\n\tcursor: pointer;\n}\n/* keep the builder canvas clickable */\n.bricks-area %root% a::after,\n.iframe %root% a::after {\n\tcontent: none;\n}{{cursor}}", "position: relative;\n\na {\n\t@include clickable-parent;\n}{{cursor}}", false );
		$r['clickable-card']   = array( 'Interaction', "/* Clickable card: the heading link covers the whole card and a screen reader hears one link (same as the .is-clickable class) */\n%root% {\n\tposition: relative;\n}\n%root% :is(h1, h2, h3, h4, h5, h6) a::after {\n\tcontent: \"\";\n\tposition: absolute;\n\tinset: 0;\n\tborder-radius: var(--card-radius, var(--radius));\n}\n/* a second link or a button in the card needs .is-above (or this) to stay clickable; text in the card cannot be selected with the mouse */\n%root% .is-above {\n\tposition: relative;\n\tz-index: 1;\n}\n/* the focus ring is drawn just inside the card edge, so a card with overflow: clip or hidden does not cut it off */\n%root% :is(h1, h2, h3, h4, h5, h6) a:focus-visible {\n\toutline: none;\n}\n%root% :is(h1, h2, h3, h4, h5, h6) a:focus-visible::after {\n\toutline: var(--focus-width, 2px) solid var(--focus-color, currentColor);\n\toutline-offset: calc(-1 * var(--focus-width, 2px));\n}\n/* keep the builder canvas clickable */\n.bricks-area %root% :is(h1, h2, h3, h4, h5, h6) a::after {\n\tcontent: none;\n}{{cursor}}", "position: relative;\n\n:is(h1, h2, h3, h4, h5, h6) a {\n\t@include clickable-parent;\n}{{cursor}}", false );
		$r['focus-parent']     = array( 'Interaction', "/* Focus parent: the whole card shows the focus ring when its link is focused */\n%root%:focus-within {\n\toutline: var(--focus-width) solid var(--focus-color);\n\toutline-offset: var(--focus-offset);\n}\n%root%:focus-within:not(:has(:focus-visible)) {\n\toutline: none;\n}\n%root% :focus {\n\toutline: none !important;\n\tbox-shadow: none !important;\n}{{cursor}}", "@include focus-parent(outline);{{cursor}}", false );
		$r['btn']              = array( 'Interaction', "/* Custom button colours (use with a btn--* class) */\n%root% {\n\t--btn-background: var(--primary);\n\t--btn-background-hover: var(--primary-hover);\n\t--btn-text-color: var(--primary-ultra-light);\n\t--btn-text-color-hover: var(--primary-ultra-light);\n\t--btn-border-color: var(--primary);\n\t--btn-border-color-hover: var(--primary-hover);\n\t--btn-border-width: 1px;\n\t--focus-color: var(--primary-light);\n}{{cursor}}", '@include btn(primary{{cursor}});', false );

		// ---- Layout helpers.
		$r['columns']           = array( 'Layout', "/* Newspaper columns that add/remove themselves with the width */\n%root% {\n\t--col-count: auto;\n\t--col-min-width: 250px;\n\t--col-gap: var(--grid-gap);\n\t--col-rule: 1px solid var(--border-color, currentColor);\n\t--row-gap: var(--grid-gap);\n\tdisplay: block !important;\n\tcolumns: var(--col-count) var(--col-min-width);\n\tcolumn-gap: var(--col-gap);\n\tcolumn-rule: var(--col-rule);\n}\n%root% > * {\n\tbreak-inside: avoid;\n\tmargin-block-end: var(--row-gap);\n}{{cursor}}", null, false );
		$r['divider-all']       = array( 'Layout', "/* A divider between every child (tokens: --divider, --divider-gap, --divider-size, --divider-inline-size) */\n%root% {\n\trow-gap: 0 !important;\n}\n%root% > :not(:last-child) {\n\tposition: relative;\n\toverflow: visible;\n\tmargin-block-end: calc(var(--divider-gap) * 2);\n}\n%root% > :not(:last-child)::after {\n\tcontent: \"\";\n\tposition: absolute;\n\tinset-inline-start: 0;\n\tinset-block-end: calc(-1 * var(--divider-gap) - var(--divider-size, 1px));\n\tinline-size: var(--divider-inline-size, 100%);\n\tborder-block-end: var(--divider);\n}{{cursor}}", null, false );
		$r['divider-bottom']    = array( 'Layout', "/* A divider under this element (not after the last one) */\n:has(> %root%) {\n\tgap: 0 !important;\n}\n%root% {\n\tposition: relative;\n\toverflow: visible;\n\tmargin-block-end: calc(var(--divider-gap) * 2);\n}\n%root%:not(:last-child)::before {\n\tcontent: \"\";\n\tposition: absolute;\n\tinset-inline-start: 0;\n\tinset-block-end: calc(-1 * var(--divider-gap) - var(--divider-size, 1px));\n\tinline-size: var(--divider-inline-size, 100%);\n\tborder-block-end: var(--divider);\n}{{cursor}}", null, false );
		$r['divider-top']       = array( 'Layout', "/* A divider above this element (not before the first one) */\n:has(> %root%) {\n\tgap: 0 !important;\n}\n%root% {\n\tposition: relative;\n\toverflow: visible;\n\tmargin-block-start: calc(var(--divider-gap) * 2);\n}\n%root%:not(:first-child)::before {\n\tcontent: \"\";\n\tposition: absolute;\n\tinset-inline-start: 0;\n\tinset-block-start: calc(-1 * var(--divider-gap) - var(--divider-size, 1px));\n\tinline-size: var(--divider-inline-size, 100%);\n\tborder-block-end: var(--divider);\n}{{cursor}}", null, false );
		$r['line-clamp']        = array( 'Layout', "/* Cut the text after --line-count lines */\n%root% {\n\t--line-count: 3;\n\tdisplay: -webkit-box;\n\t-webkit-box-orient: vertical;\n\t-webkit-line-clamp: var(--line-count);\n\tline-clamp: var(--line-count);\n\toverflow: hidden;\n}{{cursor}}", '@include line-clamp(3{{cursor}});', false );
		$r['concentric-radius'] = array( 'Layout', "/* Concentric corners: the outer radius = inner radius + half the padding */\n%root% {\n\t--padding: var(--space-m);\n\tpadding: var(--padding);\n\tborder-radius: calc(var(--radius) + var(--padding) / 2);\n}\n/* the child that gets the inner radius */\n%root% > * {\n\tborder-radius: var(--radius);\n\toverflow: hidden;\n}{{cursor}}", null, false );
		$r['list-none']         = array( 'Layout', "/* A list without bullets or indent (use %root% > ul on a wrapper) */\n%root% {\n\tlist-style: none;\n\tpadding-inline-start: 0;\n\tmargin-block: 0;\n}{{cursor}}", null, false );
		$r['border']            = array( 'Layout', "/* Border + radius from the tokens (on the img inside figures) */\n%root%:where(:not(figure, picture)),\n%root%:where(figure, picture) > img {\n\tborder: var(--border);\n\tborder-radius: var(--radius);\n}{{cursor}}", '@include border;{{cursor}}', false );
		$r['overlap']           = array( 'Layout', "/* This section's background stops short so the next one overlaps it */\n%root% {\n\t--overlap-background: var(--bg-ultra-light);\n\t--overlap-foreground: inherit;\n\t--overlap-amount: 8vmin;\n\t--overlap-overlay: linear-gradient(transparent, transparent);\n\tposition: relative;\n\tisolation: isolate;\n}\n%root% :is(h1, h2, h3, h4, h5, h6, p) {\n\tcolor: var(--overlap-foreground);\n}\n%root%::before {\n\tcontent: \"\";\n\tposition: absolute;\n\tinset-block-start: 0;\n\tinset-inline: 0;\n\tblock-size: calc(100% - var(--overlap-amount));\n\tbackground: var(--overlap-background);\n\tborder-image: fill 1 var(--overlap-overlay);\n\tz-index: -1;\n}{{cursor}}", null, false );
		$r['overlap-alt']       = array( 'Layout', "/* The NEXT section's background extends up behind this one */\n%root% + * {\n\t--overlap-background: var(--bg-ultra-light);\n\t--overlap-foreground: inherit;\n\t--overlap-amount: 100px;\n\t--overlap-overlay: linear-gradient(transparent, transparent);\n\tposition: relative;\n}\n%root% + * :is(h1, h2, h3, h4, h5, h6, p) {\n\tcolor: var(--overlap-foreground);\n}\n%root% + *::before {\n\tcontent: \"\";\n\tposition: absolute;\n\tinset-block-end: 0;\n\tinset-inline: 0;\n\tblock-size: calc(100% + var(--overlap-amount));\n\tbackground: var(--overlap-background);\n\tborder-image: fill 1 var(--overlap-overlay);\n\tz-index: -1;\n}{{cursor}}", null, false );

		// ---- Fades (mask).
		$fades = array(
			'block'  => 'to bottom, transparent 0%, #000 var(--fade-amount), #000 calc(100% - var(--fade-amount)), transparent 100%',
			'inline' => 'to right, transparent 0%, #000 var(--fade-amount), #000 calc(100% - var(--fade-amount)), transparent 100%',
			'top'    => 'to bottom, transparent 0%, #000 var(--fade-amount)',
			'right'  => 'to left, transparent 0%, #000 var(--fade-amount)',
			'bottom' => 'to top, transparent 0%, #000 var(--fade-amount)',
			'left'   => 'to right, transparent 0%, #000 var(--fade-amount)',
		);
		foreach ( $fades as $axis => $g ) {
			$r[ "fade-$axis" ] = array( 'Fades', "/* Fade out: $axis */\n%root% {\n\t--fade-amount: 25%;\n\t-webkit-mask-image: linear-gradient($g);\n\tmask-image: linear-gradient($g);\n}{{cursor}}", "@include fade($axis, 25%{{cursor}});", false );
		}

		// ---- Code snippets.
		$r['query-children'] = array( 'Code', "return [\n\t'post_type'           => 'page', /* the post type */\n\t'post_parent__not_in' => [ 0 ],  /* only child posts */\n\t'posts_per_page'      => -1,\n\t'post_status'         => 'publish',\n];{{cursor}}", null, false );
		$r['property']       = array( 'Code', "@property --{{cursor}}name {\n\tsyntax: \"<length>\";\n\tinherits: false;\n\tinitial-value: 0px;\n}", null, false );

		return $r;
	}

	/**
	 * Every built-in recipe for the editors.
	 *
	 * kind: 'value' = a property value (e.g. a colour), 'block' = declarations or
	 * rules, 'text' = not CSS (placeholder copy, script and query snippets for
	 * Bricks elements), never offered in CSS editors.
	 *
	 * @return array [ [ 'n' => name, 'c' => category, 'code' => …, 'scss' => …|null, 'inline' => bool, 'kind' => string ], … ]
	 */
	public static function all() {
		$out  = array();
		$text = array( 'ipsum-short', 'script', 'query-children' );
		foreach ( self::builtin() as $name => $r ) {
			$kind          = in_array( $name, $text, true ) ? 'text' : ( $r[3] ? 'value' : 'block' );
			$out[ $name ] = array( 'n' => $name, 'c' => $r[0], 'code' => $r[1], 'scss' => $r[2], 'inline' => (bool) $r[3], 'kind' => $kind );
		}
		return array_values( $out );
	}

	/**
	 * Data for the editors (null when recipes are off).
	 *
	 * @return array|null
	 */
	public static function for_editor() {
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return null;
		}
		return array(
			'inputs'  => ! empty( $s['inputs'] ),
			'recipes' => self::all(),
		);
	}
}
