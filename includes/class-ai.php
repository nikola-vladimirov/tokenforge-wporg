<?php
/**
 * AI agents: make an AI that builds this site through MCP (the WordPress MCP
 * Adapter, Bricks' AI abilities, or any client using the Abilities API) use the
 * TokenForge framework instead of hard-coded CSS.
 *
 * - Registers read-only abilities (exposed as MCP tools): get-framework,
 *   search, check-css, get-recipe, compile-scss.
 * - Adds a short note to the MCP server instructions every client reads on
 *   connect.
 * - Appends TokenForge guidance to Bricks' orientation ("start here") and adds a
 *   compact "tokenforge" block to Bricks' design context, the two things an
 *   agent reads before it builds.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities + agent guidance.
 */
class Ai {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$s = Settings::get_instance()->get_settings();
		if ( ! empty( $s['ai_abilities'] ) ) {
			add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
			add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		}
		if ( ! empty( $s['ai_guidance'] ) ) {
			add_filter( 'mcp_adapter_default_server_config', array( $this, 'server_config' ), 20 );
			add_filter( 'bricks/abilities/start_here_text', array( $this, 'bricks_start_here' ) );
			add_filter( 'wp_ability_execute_result', array( $this, 'enrich_result' ), 10, 2 );
			if ( version_compare( get_bloginfo( 'version' ), '7.1', '<' ) ) {
				// Before WordPress 7.1 there is no ability result filter: enrich at the MCP layer.
				add_filter( 'mcp_adapter_tool_call_result', array( $this, 'enrich_tool_result' ), 10, 3 );
			}
		}
	}

	/**
	 * MCP-layer fallback of enrich_result() (direct tools and the dispatcher).
	 *
	 * @param mixed  $result    Result.
	 * @param array  $args      Tool arguments.
	 * @param string $tool_name Tool name.
	 * @return mixed
	 */
	public function enrich_tool_result( $result, $args, $tool_name ) {
		if ( 'mcp-adapter-execute-ability' === $tool_name && is_array( $result ) && isset( $result['data'], $args['ability_name'] ) ) {
			$result['data'] = $this->enrich_result( $result['data'], (string) $args['ability_name'] );
			return $result;
		}
		$map = array( 'bricks-get-design-context' => 'bricks/get-design-context', 'bricks-start-here' => 'bricks/start-here' );
		return isset( $map[ $tool_name ] ) ? $this->enrich_result( $result, $map[ $tool_name ] ) : $result;
	}

	/**
	 * The shortest possible pointer, for places every session reads.
	 *
	 * @return string
	 */
	public static function pointer() {
		return 'This site is styled with the TokenForge CSS framework. Before creating or styling pages, call the tool tokenforge-get-framework once, then build with its classes and var(--tokens) instead of hard-coded colours, sizes and spacing. Check new CSS with tokenforge-check-css; look things up with tokenforge-search.';
	}

	// ------------------------------------------------------------------
	// Abilities
	// ------------------------------------------------------------------

	/**
	 * Ability category.
	 *
	 * @return void
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'tokenforge',
			array(
				'label'       => 'TokenForge',
				'description' => 'The site’s CSS framework: design tokens (CSS variables), utility and component classes, SCSS mixins and recipes.',
			)
		);
	}

	/**
	 * Common ability meta: read-only tools, public to REST and MCP.
	 *
	 * @return array
	 */
	private function meta() {
		return array(
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
		);
	}

	/**
	 * Permission: anyone who can edit content.
	 *
	 * @return bool
	 */
	public function can_use() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Register the abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$perm = array( $this, 'can_use' );
		wp_register_ability(
			'tokenforge/get-framework',
			array(
				'label'               => 'TokenForge: framework guide',
				'description'         => 'Call once before building or styling any page, section or template on this site. Returns how the TokenForge CSS framework works (rules, page structure, automatic spacing and typography), the design tokens with their current values, and every class group with examples. Build with these classes and var(--tokens) instead of hard-coded colours, font sizes, spacing, radius and shadows. detail=full returns every class and variable of the requested groups.',
				'category'            => 'tokenforge',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'detail' => array( 'type' => 'string', 'enum' => array( 'summary', 'full' ), 'default' => 'summary', 'description' => 'summary: guide + tokens + examples per group. full: also every item of the requested groups.' ),
						'groups' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Group ids for detail=full, e.g. ["buttons","spacing","v-color"]. Empty = all.' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'get_framework' ),
				'permission_callback' => $perm,
				'meta'                => $this->meta(),
			)
		);
		wp_register_ability(
			'tokenforge/search',
			array(
				'label'               => 'TokenForge: search classes and variables',
				'description'         => 'Find TokenForge classes, CSS variables, SCSS mixins, recipes and data attributes by words, e.g. "outline button", "section padding", "primary dark", "card". Each result has the name, what it does, the CSS it applies and the exact text to use.',
				'category'            => 'tokenforge',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query' => array( 'type' => 'string', 'description' => 'Words to look for (all must match).' ),
						'kind'  => array( 'type' => 'string', 'enum' => array( '', 'class', 'var', 'mixin', 'function', 'recipe', 'attr' ), 'description' => 'Only this kind of item.' ),
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 40 ),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'search' ),
				'permission_callback' => $perm,
				'meta'                => $this->meta(),
			)
		);
		wp_register_ability(
			'tokenforge/check-css',
			array(
				'label'               => 'TokenForge: check CSS against the framework',
				'description'         => 'Run on CSS (and optionally HTML) before saving or importing it. Flags hard-coded colours, font sizes, spacing, radius, shadows and widths and suggests the matching TokenForge var(--token); warns when CSS redefines a TokenForge class; flags HTML class names that look like misspelt TokenForge classes and data-tf-* attribute names or values that do not exist. Nothing is changed.',
				'category'            => 'tokenforge',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'css'  => array( 'type' => 'string', 'description' => 'CSS to check.' ),
						'html' => array( 'type' => 'string', 'description' => 'Optional HTML whose class names should be checked.' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'check_css' ),
				'permission_callback' => $perm,
				'meta'                => $this->meta(),
			)
		);
		wp_register_ability(
			'tokenforge/get-recipe',
			array(
				'label'               => 'TokenForge: get a recipe',
				'description'         => 'Returns the ready-made CSS of a TokenForge recipe (auto-grid, clickable-parent, divider-all, fade-bottom, breakpoint-m, primary-clr …). In the CSS, %root% stands for the element’s own selector. Use tokenforge-search with kind=recipe to list recipes.',
				'category'            => 'tokenforge',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name' => array( 'type' => 'string', 'description' => 'Recipe name, with or without the leading ?.' ),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'get_recipe' ),
				'permission_callback' => $perm,
				'meta'                => $this->meta(),
			)
		);
		wp_register_ability(
			'tokenforge/compile-scss',
			array(
				'label'               => 'TokenForge: compile SCSS',
				'description'         => 'Compiles SCSS with the TokenForge mixins and functions available (btn, card, center, auto-grid, breakpoint, fluid() …) and returns plain CSS you can put in an element’s or class’s custom CSS. Nothing is saved.',
				'category'            => 'tokenforge',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scss' => array( 'type' => 'string', 'description' => 'SCSS source.' ),
					),
					'required'             => array( 'scss' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'compile_scss' ),
				'permission_callback' => $perm,
				'meta'                => $this->meta(),
			)
		);
	}

	/**
	 * Execute: framework guide.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function get_framework( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$full   = isset( $input['detail'] ) && 'full' === $input['detail'];
		$want   = isset( $input['groups'] ) && is_array( $input['groups'] ) ? array_map( 'strval', $input['groups'] ) : array();
		$groups = array();
		foreach ( Cheatsheet::data() as $g ) {
			$row = array(
				'id'       => $g['id'],
				'title'    => $g['title'],
				'about'    => $g['desc'],
				'count'    => count( $g['items'] ),
				'examples' => array(),
			);
			foreach ( array_slice( self::examples( $g ), 0, 8 ) as $it ) {
				$row['examples'][] = self::item( $it, false );
			}
			if ( $full && ( ! $want || in_array( $g['id'], $want, true ) ) ) {
				$row['items'] = array_map( array( __CLASS__, 'item' ), $g['items'] );
			}
			$groups[] = $row;
		}
		return array(
			'framework' => 'TokenForge ' . TOKENFORGE_VERSION,
			'guide'     => self::guide(),
			'tokens'    => self::tokens(),
			'groups'    => $groups,
			'tools'     => array(
				'tokenforge-search'       => 'Find a class, variable, mixin or recipe by words.',
				'tokenforge-check-css'    => 'Check CSS/HTML for hard-coded values and misspelt classes before saving.',
				'tokenforge-get-recipe'   => 'Get a ready-made CSS snippet.',
				'tokenforge-compile-scss' => 'Compile SCSS that uses TokenForge mixins into CSS.',
			),
		);
	}

	/**
	 * Useful examples first: the core items of a group, not 50 transparency steps.
	 *
	 * @param array $g Group.
	 * @return array
	 */
	private static function examples( $g ) {
		$items = $g['items'];
		usort(
			$items,
			function ( $a, $b ) {
				$pa = preg_match( '/trans-|-to-|shade|link-|font-|line-height|padding-x|width-safe|-h$|-hex$/', $a['n'] ) ? 1 : 0;
				$pb = preg_match( '/trans-|-to-|shade|link-|font-|line-height|padding-x|width-safe|-h$|-hex$/', $b['n'] ) ? 1 : 0;
				if ( $pa !== $pb ) {
					return $pa - $pb;
				}
				return ( '' === $a['d'] ? 1 : 0 ) - ( '' === $b['d'] ? 1 : 0 );
			}
		);
		return $items;
	}

	/**
	 * A cheatsheet item for agents.
	 *
	 * @param array $it Item.
	 * @return array
	 */
	public static function item( $it, $with_css = true ) {
		$out = array( 'name' => $it['n'], 'use' => $it['copy'], 'kind' => $it['k'] );
		if ( '' !== $it['d'] ) {
			$out['does'] = $it['d'];
		}
		if ( $with_css && '' !== $it['css'] && $it['css'] !== $it['copy'] ) {
			$out['css'] = $it['css'];
		}
		return $out;
	}

	/**
	 * Execute: search.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function search( $input = array() ) {
		$q     = isset( $input['query'] ) ? (string) $input['query'] : '';
		$kind  = isset( $input['kind'] ) ? (string) $input['kind'] : '';
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 40;
		$res   = array();
		foreach ( Cheatsheet::search( $q, $kind, $limit ) as $it ) {
			$res[] = self::item( $it ) + array( 'group' => $it['group'] );
		}
		return array( 'query' => $q, 'count' => count( $res ), 'results' => $res );
	}

	/**
	 * Execute: recipe.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function get_recipe( $input = array() ) {
		$name = Recipes::name( isset( $input['name'] ) ? $input['name'] : '' );
		foreach ( Recipes::all() as $r ) {
			if ( $r['n'] === $name ) {
				return array(
					'name'  => $name,
					'css'   => str_replace( '{{cursor}}', '', (string) $r['code'] ),
					'scss'  => str_replace( '{{cursor}}', '', (string) ( $r['scss'] ? $r['scss'] : str_replace( '%root%', '&', (string) $r['code'] ) ) ),
					'value' => $r['inline'],
					'note'  => '%root% is the element’s own selector (Bricks replaces it); in SCSS use &.',
				);
			}
		}
		return new \WP_Error( 'tokenforge_unknown_recipe', 'Unknown recipe. Use tokenforge-search with kind=recipe to list them.' );
	}

	/**
	 * Execute: compile SCSS.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function compile_scss( $input = array() ) {
		$r = Scss::compile( isset( $input['scss'] ) ? (string) $input['scss'] : '' );
		return array( 'ok' => null === $r['error'], 'css' => $r['css'], 'error' => $r['error'] );
	}

	// ------------------------------------------------------------------
	// Guide + tokens
	// ------------------------------------------------------------------

	/**
	 * Numeric px of a CSS length or the [min, max] of a clamp().
	 *
	 * @param string $v Value.
	 * @return array|null [ min_px, max_px ]
	 */
	public static function px_range( $v ) {
		$r   = Defaults::root_px();
		$len = function ( $x ) use ( $r ) {
			if ( preg_match( '/^\s*(-?[\d.]+)(px|rem|em)?\s*$/', $x, $m ) ) {
				return (float) $m[1] * ( 'px' === ( isset( $m[2] ) ? $m[2] : '' ) || ! isset( $m[2] ) ? 1 : $r );
			}
			return null;
		};
		$v = trim( (string) $v );
		if ( preg_match( '/^clamp\(\s*([^,]+),.*,\s*([^,()]+)\)$/', $v, $m ) ) {
			$a = $len( $m[1] );
			$b = $len( $m[2] );
			return null === $a || null === $b ? null : array( round( $a, 1 ), round( $b, 1 ) );
		}
		$x = $len( $v );
		return null === $x ? null : array( round( $x, 1 ), round( $x, 1 ) );
	}

	/**
	 * Current tokens, compact.
	 *
	 * @return array
	 */
	public static function tokens() {
		$vars = array();
		foreach ( Token_Catalog::every_variable() as $t ) {
			if ( ! empty( $t['enabled'] ) && '' !== (string) $t['value'] ) {
				$vars[ $t['name'] ] = (string) $t['value'];
			}
		}
		$resolve = function ( $v, $depth = 0 ) use ( &$resolve, $vars ) {
			if ( $depth < 5 && preg_match( '/^var\(--([a-z0-9\-]+)\)$/', trim( $v ), $m ) && isset( $vars[ $m[1] ] ) ) {
				return $resolve( $vars[ $m[1] ], $depth + 1 );
			}
			return $v;
		};
		$range = function ( $name ) use ( $vars, $resolve ) {
			if ( ! isset( $vars[ $name ] ) ) {
				return null;
			}
			$r = self::px_range( $resolve( $vars[ $name ] ) );
			return $r ? ( $r[0] === $r[1] ? $r[0] . 'px' : $r[0] . '-' . $r[1] . 'px' ) : $vars[ $name ];
		};
		$out = array( 'colors' => array(), 'text' => array(), 'headings' => array(), 'space' => array(), 'sectionSpace' => array(), 'radius' => array(), 'shadows' => array(), 'layout' => array() );
		foreach ( Components::FAMILIES as $f ) {
			if ( ! isset( $vars[ $f ] ) ) {
				continue;
			}
			$base   = isset( $vars[ "$f-hex" ] ) ? $vars[ "$f-hex" ] : $resolve( $vars[ $f ] );
			$shades = array();
			foreach ( array( 'ultra-light', 'light', 'semi-light', 'hover', 'semi-dark', 'dark', 'ultra-dark' ) as $sh ) {
				if ( isset( $vars[ "$f-$sh" ] ) ) {
					$shades[] = "--$f-$sh";
				}
			}
			$out['colors'][ "--$f" ] = array( 'value' => self::short_color( $base ), 'shades' => $shades );
		}
		foreach ( array( 'bg-ultra-light', 'bg-light', 'bg-dark', 'bg-ultra-dark', 'text-dark', 'text-dark-muted', 'text-light', 'text-light-muted', 'white', 'black' ) as $n ) {
			if ( isset( $vars[ $n ] ) ) {
				$out['colors'][ "--$n" ] = array( 'value' => self::short_color( $resolve( $vars[ $n ] ) ) );
			}
		}
		foreach ( Scale_Engine::STEPS as $st ) {
			$out['text'][ "--text-$st" ]            = $range( "text-$st" );
			$out['space'][ "--space-$st" ]          = $range( "space-$st" );
			$out['sectionSpace'][ "--section-space-$st" ] = $range( "section-space-$st" );
			$out['radius'][ "--radius-$st" ]        = $range( "radius-$st" );
		}
		for ( $i = 1; $i <= 6; $i++ ) {
			$out['headings'][ "--h$i" ] = $range( "h$i" );
		}
		$out['radius']['--radius'] = $range( 'radius' );
		for ( $i = 1; $i <= 5; $i++ ) {
			if ( isset( $vars[ "shadow-$i" ] ) ) {
				$out['shadows'][ "--shadow-$i" ] = $vars[ "shadow-$i" ];
			}
		}
		foreach ( array( 'content-width', 'content-width-safe', 'gutter', 'container-gap', 'content-gap', 'grid-gap', 'header-height' ) as $n ) {
			if ( isset( $vars[ $n ] ) ) {
				$out['layout'][ "--$n" ] = $range( $n );
			}
		}
		foreach ( $out as $k => $list ) {
			$out[ $k ] = array_filter( $list, function ( $x ) { return null !== $x; } );
		}
		$out['breakpoints'] = Scss::breakpoints();
		return $out;
	}

	/**
	 * Light value of a light-dark() colour, else the value.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	private static function short_color( $v ) {
		$p = self::light_dark( $v );
		if ( $p ) {
			return $p[0] === $p[1] ? $p[0] : $p[0] . ' (dark mode: ' . $p[1] . ')';
		}
		return $v;
	}

	/**
	 * The two values of light-dark(a, b), or null.
	 *
	 * @param string $v Value.
	 * @return array|null
	 */
	public static function light_dark( $v ) {
		$v = trim( (string) $v );
		if ( 0 !== stripos( $v, 'light-dark(' ) || ')' !== substr( $v, -1 ) ) {
			return null;
		}
		$inner = substr( $v, 11, -1 );
		$depth = 0;
		for ( $i = 0, $n = strlen( $inner ); $i < $n; $i++ ) {
			if ( '(' === $inner[ $i ] ) {
				$depth++;
			} elseif ( ')' === $inner[ $i ] ) {
				$depth--;
			} elseif ( ',' === $inner[ $i ] && 0 === $depth ) {
				return array( trim( substr( $inner, 0, $i ) ), trim( substr( $inner, $i + 1 ) ) );
			}
		}
		return null;
	}

	/**
	 * The framework guide for agents (Markdown), with this site's settings.
	 *
	 * @return string
	 */
	public static function guide() {
		$c      = Components::settings();
		$sc     = Scale_Engine::settings();
		$sch    = Scheme::settings();
		$bp     = Scss::breakpoints();
		$fams   = array_values( array_filter( array_map( 'trim', explode( ',', (string) $c['btn_families'] ) ) ) );
		$bricks = Platforms\Bricks::is_active();
		$lines  = array(
			'# TokenForge: how this site is styled',
			'TokenForge generates the site’s CSS: design tokens (CSS variables), utility and component classes, and base styles for sections, typography, links, buttons and cards. Build with them; do not re-invent them in custom CSS.',
			'',
			'## Rules',
			'1. **No hard-coded design values.** Colours, font sizes, spacing, radius, shadows and widths come from tokens: `var(--primary)`, `var(--base-dark)`, `var(--text-l)`, `var(--h2)`, `var(--space-m)`, `var(--section-space-l)`, `var(--radius)`, `var(--shadow-2)`, `var(--content-width)`. Run `tokenforge-check-css` on your CSS to catch misses.',
			'2. **Use framework classes first.** Buttons `btn--primary`, cards `card`, section spacing `section--l`, backgrounds `bg--dark`, text sizes `text--l`, heading looks `h3`, grids `grid-3`, gaps `gap-m`, padding `pad-m`. Look anything up with `tokenforge-search` (e.g. "outline button").',
			'3. **Never restyle or redefine a TokenForge class** and never create a class or variable with a TokenForge name. For custom styling add your own BEM class (block__element--modifier) and use tokens inside it.',
			'4. **Let the automatic layout work.** Top-level `<section>` elements already get side gutters (`var(--gutter)`) and vertical padding (`var(--section-space-m)`)' . ( ! empty( $c['auto_container_gap'] ) ? '; their children are spaced by `var(--container-gap)`' : '' ) . ( ! empty( $c['auto_content_gap'] ) ? ', and children of containers/blocks/divs by `var(--content-gap)`' : '' ) . '. Do not add margins between stacked elements or padding to sections unless the design needs a different size: then use `section--{size}` or `section--{a}-to-{b}`.',
			'5. **Typography is global.** Body text and h1 to h6 already have fonts, fluid sizes, line heights and colours. Do not set font-family, font-size or line-height on headings or paragraphs; to make any element look like a heading use the class `h2`, for bigger body text `text--l`.',
			'6. **Colour contexts.** On a dark section use `bg--dark` or `bg--ultra-dark`: text, headings, links and buttons inside switch to readable colours automatically. Light: `bg--light`, `bg--ultra-light`.' . ( 'auto' === $sch['dark_mode'] ? ' Dark mode is on: colour tokens have light and dark values (light-dark()), so always use tokens.' : '' ),
			'7. **Fluid by default.** Sizes scale smoothly between ' . (int) $sc['min_vw'] . 'px and ' . (int) $sc['max_vw'] . 'px screens, so most elements need no breakpoints. For sizes that must shrink more on mobile use the "-to-" versions: `var(--space-xl-to-m)`, `var(--h1-to-h3)`, `section--xl-to-m`. Breakpoints (max-width): ' . implode( ', ', array_map( function ( $k, $v ) { return "$k $v" . 'px'; }, array_keys( $bp ), $bp ) ) . '.',
			'8. **Accessible defaults.** Keep the focus styles; use `hidden-accessible` for screen-reader-only text; one h1 per page and headings in order.',
			'',
			'## Page structure',
			$bricks
				? 'Bricks: `<section>` (full width, gets gutters and padding) → `<div class="brxe-container">` (' . ( ! empty( $c['container_width'] ) ? 'centred at the content width' : 'Bricks container' ) . ') → content. Put TokenForge class names straight into `class=""`: they already exist as Bricks global classes, so do not send CSS rules for them and do not recreate them.'
				: 'Use `<section>` for full-width bands (they get gutters and padding) and a `<div class="container">` inside to centre content at the content width.',
			'Content grid: `content-grid` on a section makes children sit in a centred column; `content--feature`, `content--feature-max`, `content--full` break out wider.',
			'',
			'## Components',
			'- **Buttons:** any `btn--*` class makes a button. Colours: ' . ( $fams ? implode( ', ', array_map( function ( $f ) { return '`btn--' . $f . '`'; }, $fams ) ) : '`btn--primary`' ) . '. Variants: add `-dark` / `-light` to the colour (`btn--primary-dark`), add `btn--outline` for an outline. Sizes: `btn--s` … `btn--xl`. Example: `<a class="btn--primary btn--l" href="…">Get started</a>`.',
			'- **Cards:** `card` = padded flex column with background, radius, border and shadow. Inside it, elements whose class contains `__media` (images) and `__avatar` are styled; headings use `var(--card-heading-size)`. Example: `<article class="card"><img class="card__media" …><h3>…</h3><p>…</p></article>`.',
			'- **Icons:** add `data-icon` to an svg or i element; size with `data-icon-size="l"` or `icon--l`; `icon--boxed` for a box.',
			'- **Grids:** `grid-2` … `grid-12` (equal columns), `grid-1-2`, `grid-2-1` (ratios), or `grid--auto-3` (same as `grid-template-columns: var(--grid-auto-3)`) for columns that wrap on their own.',
			'- **Effects:** `on-enter--fade`, `on-enter--float`, `on-enter-all--fade` (children one after another), `on-hover--grow`, `on-hover--shadow`.',
			'- **Backgrounds with images:** put the image first inside the section with class `is-bg`; add `overlay` to the section for a tint.',
			'',
			'## Custom CSS',
			'Write it on your own class with tokens only, e.g. `.pricing__badge { padding: var(--space-xs) var(--space-s); background: var(--accent-light); border-radius: var(--radius-s); font-size: var(--text-s); }`. Ready-made snippets: `tokenforge-get-recipe` (auto-grid, clickable-parent, divider-all, fade-bottom, line-clamp …). SCSS with mixins (`@include btn(primary);`, `@include breakpoint(m) {…}`) can be compiled with `tokenforge-compile-scss`.',
		);
		return implode( "\n", $lines );
	}

	// ------------------------------------------------------------------
	// CSS check
	// ------------------------------------------------------------------

	/**
	 * Execute: check CSS/HTML.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function check_css( $input = array() ) {
		$css  = isset( $input['css'] ) ? (string) $input['css'] : '';
		$html = isset( $input['html'] ) ? (string) $input['html'] : '';
		$vars = array();
		foreach ( Token_Catalog::every_variable() as $t ) {
			if ( ! empty( $t['enabled'] ) && '' !== (string) $t['value'] ) {
				$vars[ $t['name'] ] = (string) $t['value'];
			}
		}
		$classes = array_flip( Features\Builder_Enhancements::menu_classes() );
		$palette = self::palette( $vars );
		$scales  = self::scales( $vars );
		$out     = array();
		$warn    = array();

		$rules = array();
		self::rules( preg_replace( '#/\*.*?\*/#s', '', $css ), '', $rules );
		foreach ( $rules as $rule ) {
			list( $ctx, $sel, $body ) = $rule;
			foreach ( explode( ',', $sel ) as $one ) {
				if ( preg_match( '/^\s*\.([a-z0-9_\-]+)\s*$/i', $one, $m ) && isset( $classes[ $m[1] ] ) ) {
					$warn[] = array( 'selector' => trim( $one ), 'problem' => 'Redefines the TokenForge class .' . $m[1] . '. Use the class as it is, or style your own BEM class instead.' );
				}
			}
			foreach ( self::decls( $body ) as $d ) {
				list( $prop, $value ) = $d;
				$prop = strtolower( $prop );
				foreach ( self::suggest( $prop, $value, $palette, $scales, $vars ) as $sug ) {
					$out[] = array( 'selector' => trim( $sel ), 'property' => $prop, 'value' => $value ) + $sug + ( '' !== $ctx ? array( 'context' => $ctx ) : array() );
					if ( count( $out ) >= 150 ) {
						break 3;
					}
				}
			}
		}

		$unknown = array();
		if ( '' !== $html && preg_match_all( '/\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $mm ) ) {
			$names = array_keys( $classes );
			foreach ( $mm[2] as $list ) {
				foreach ( preg_split( '/\s+/', trim( $list ) ) as $cls ) {
					if ( '' === $cls || isset( $classes[ $cls ] ) || isset( $unknown[ $cls ] ) || ! self::framework_like( $cls ) ) {
						continue;
					}
					$best = '';
					$bd   = 99;
					foreach ( $names as $n ) {
						if ( abs( strlen( $n ) - strlen( $cls ) ) > 3 ) {
							continue;
						}
						$dist = levenshtein( $cls, $n ) + levenshtein( preg_replace( '/[^a-z0-9]/', '', $cls ), preg_replace( '/[^a-z0-9]/', '', $n ) ) / 10;
						if ( $dist < $bd ) {
							$bd   = $dist;
							$best = $n;
						}
					}
					if ( $bd <= 3 ) {
						$unknown[ $cls ] = array( 'class' => $cls, 'didYouMean' => $best );
					}
				}
			}
		}

		// data-tf-* attributes: unknown names and values (the form extras' names are known too).
		$bad_attr = array();
		if ( '' !== $html && preg_match_all( '/\b(data-tf-[a-z0-9-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/i', $html, $am, PREG_SET_ORDER ) ) {
			$known = Attributes::all();
			foreach ( $am as $a ) {
				$name = strtolower( $a[1] );
				$val  = isset( $a[4] ) && '' !== $a[4] ? $a[4] : ( isset( $a[3] ) && '' !== $a[3] ? $a[3] : ( isset( $a[2] ) ? $a[2] : '' ) );
				if ( ! empty( $known[ $name ][0] ) ) { // an attribute with no value list takes any value
					if ( ! in_array( $val, $known[ $name ][0], true ) ) {
						$bad_attr[ $name . '=' . $val ] = array( 'attribute' => $name, 'value' => $val, 'allowed' => $known[ $name ][0] );
					}
				}
			}
			foreach ( Attributes::check_names( array_slice( array_column( $am, 1 ), 0, 200 ) ) as $u ) {
				$bad_attr[ $u['attribute'] ] = $u;
			}
		}

		return array(
			'summary'             => count( $out ) . ' value(s) could use tokens, ' . count( $warn ) . ' TokenForge class redefinition(s), ' . count( $unknown ) . ' class name(s) that look like misspelt TokenForge classes, ' . count( $bad_attr ) . ' data-tf attribute problem(s).',
			'suggestions'         => $out,
			'classRedefinitions'  => $warn,
			'misspeltClasses'     => array_values( $unknown ),
			'attributeProblems'   => array_values( $bad_attr ),
		);
	}

	/**
	 * Does a class name look like a framework class (so a near miss matters)?
	 *
	 * @param string $c Class.
	 * @return bool
	 */
	private static function framework_like( $c ) {
		return (bool) preg_match( '/^(btn|bg|text|section|header|grid|gap|pad|mar|card|icon|radius|shadow|content|container|width|border|divider|h[1-6]|on-|overlay|surface|flex|items|justify)[-_]/', $c ) && false === strpos( $c, '__' );
	}

	/**
	 * Collect style rules (recursing into at-rules).
	 *
	 * @param string $css   CSS.
	 * @param string $ctx   At-rule context.
	 * @param array  $rules Out: [ ctx, selector, body ].
	 * @return void
	 */
	private static function rules( $css, $ctx, &$rules ) {
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			$open = strpos( $css, '{', $i );
			if ( false === $open ) {
				break;
			}
			$sel   = trim( substr( $css, $i, $open - $i ) );
			$depth = 1;
			$j     = $open + 1;
			while ( $j < $len && $depth > 0 ) {
				if ( '{' === $css[ $j ] ) {
					$depth++;
				} elseif ( '}' === $css[ $j ] ) {
					$depth--;
				}
				$j++;
			}
			$body = substr( $css, $open + 1, $j - $open - 2 );
			$i    = $j;
			if ( '' !== $sel && '@' === $sel[0] ) {
				self::rules( $body, $sel, $rules );
			} elseif ( '' !== $sel ) {
				// Nested CSS: only the declarations of this level.
				$rules[] = array( $ctx, $sel, preg_replace( '/[^;{}]*\{[^{}]*\}/', '', $body ) );
			}
		}
	}

	/**
	 * Declarations of a rule body.
	 *
	 * @param string $body Body.
	 * @return array [ [ prop, value ], … ]
	 */
	private static function decls( $body ) {
		$out   = array();
		$depth = 0;
		$cur   = '';
		$len   = strlen( $body );
		for ( $i = 0; $i <= $len; $i++ ) {
			$ch = $i < $len ? $body[ $i ] : ';';
			if ( '(' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch ) {
				$depth--;
			}
			if ( ';' === $ch && 0 === $depth ) {
				$p = strpos( $cur, ':' );
				if ( false !== $p ) {
					$out[] = array( trim( substr( $cur, 0, $p ) ), trim( str_ireplace( '!important', '', substr( $cur, $p + 1 ) ) ) );
				}
				$cur = '';
			} else {
				$cur .= $ch;
			}
		}
		return $out;
	}

	/**
	 * Colour tokens as rgb.
	 *
	 * @param array $vars Tokens.
	 * @return array name => [ r, g, b ]
	 */
	private static function palette( $vars ) {
		$out = array();
		foreach ( $vars as $n => $v ) {
			if ( preg_match( '/-(h|s|l|r|g|b|rgb|hsl)$/', $n ) || preg_match( '/^(btn|card|icon|link|focus|overlay|surface|ribbon|blockquote|text-shadow|shadow|drop-shadow|border|divider|selection|external)/', $n ) ) {
				continue;
			}
			if ( preg_match( '/-hex$/', $n ) ) {
				continue;
			}
			$ld = self::light_dark( $v );
			$v  = $ld ? $ld[0] : trim( $v );
			if ( preg_match( '/^var\(--([a-z0-9\-]+)\)$/', trim( $v ), $m ) && isset( $vars[ $m[1] . '-hex' ] ) ) {
				$v = $vars[ $m[1] . '-hex' ];
			}
			if ( isset( $vars[ $n . '-hex' ] ) ) {
				$v = $vars[ $n . '-hex' ];
			}
			$rgb = self::rgb( $v );
			if ( $rgb ) {
				$out[ $n ] = $rgb;
			}
		}
		return $out;
	}

	/**
	 * Parse a colour to rgb (hex, rgb(), hsl()).
	 *
	 * @param string $v Colour.
	 * @return array|null
	 */
	private static function rgb( $v ) {
		$v = strtolower( trim( $v ) );
		if ( preg_match( '/^#([0-9a-f]{3,8})$/', $v, $m ) ) {
			$h = $m[1];
			if ( 3 === strlen( $h ) || 4 === strlen( $h ) ) {
				$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
			}
			return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
		}
		if ( preg_match( '/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)/', $v, $m ) ) {
			return array( (float) $m[1], (float) $m[2], (float) $m[3] );
		}
		if ( preg_match( '/^hsla?\(\s*([\d.]+)(?:deg)?[\s,]+([\d.]+)%[\s,]+([\d.]+)%/', $v, $m ) ) {
			$h = fmod( (float) $m[1], 360 ) / 360;
			$s = (float) $m[2] / 100;
			$l = (float) $m[3] / 100;
			$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
			$p = 2 * $l - $q;
			$f = function ( $t ) use ( $p, $q ) {
				$t = $t < 0 ? $t + 1 : ( $t > 1 ? $t - 1 : $t );
				return $t < 1 / 6 ? $p + ( $q - $p ) * 6 * $t : ( $t < 0.5 ? $q : ( $t < 2 / 3 ? $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6 : $p ) );
			};
			return array( round( $f( $h + 1 / 3 ) * 255 ), round( $f( $h ) * 255 ), round( $f( $h - 1 / 3 ) * 255 ) );
		}
		$named = array( 'white' => array( 255, 255, 255 ), 'black' => array( 0, 0, 0 ) );
		return isset( $named[ $v ] ) ? $named[ $v ] : null;
	}

	/**
	 * Alpha of a colour (null = opaque / unknown).
	 *
	 * @param string $c Colour.
	 * @return float|null
	 */
	private static function alpha( $c ) {
		$c = strtolower( trim( $c ) );
		if ( preg_match( '/^#[0-9a-f]{8}$/', $c ) ) {
			return hexdec( substr( $c, 7, 2 ) ) / 255;
		}
		if ( preg_match( '/^(?:rgba|hsla)\([^,]+,[^,]+,[^,]+,\s*([\d.]+)(%?)\s*\)$/', $c, $m ) || preg_match( '/\/\s*([\d.]+)(%?)\s*\)$/', $c, $m ) ) {
			return (float) $m[1] / ( '%' === $m[2] ? 100 : 1 );
		}
		return null;
	}

	/**
	 * Size scales in px (desktop value) for matching lengths.
	 *
	 * @param array $vars Tokens.
	 * @return array scale => [ var => px ]
	 */
	private static function scales( $vars ) {
		$out = array( 'space' => array(), 'section' => array(), 'text' => array(), 'radius' => array() );
		foreach ( $vars as $n => $v ) {
			if ( preg_match( '/^(space|section-space|text|radius)-(xxl|xl|l|m|s|xs)$/', $n, $m ) || preg_match( '/^(h)[1-6]$/', $n, $m ) ) {
				$v = preg_match( '/^var\(--([a-z0-9\-]+)\)$/', trim( $v ), $mm ) && isset( $vars[ $mm[1] ] ) ? $vars[ $mm[1] ] : $v;
				$r = self::px_range( $v );
				if ( ! $r ) {
					continue;
				}
				$key = array( 'space' => 'space', 'section-space' => 'section', 'text' => 'text', 'radius' => 'radius', 'h' => 'text' )[ $m[1] ];
				$out[ $key ][ $n ] = $r[1];
			}
		}
		return $out;
	}

	/**
	 * Suggestions for one declaration.
	 *
	 * @param string $prop    Property.
	 * @param string $value   Value.
	 * @param array  $palette Colours.
	 * @param array  $scales  Size scales.
	 * @param array  $vars    Tokens.
	 * @return array
	 */
	private static function suggest( $prop, $value, $palette, $scales, $vars ) {
		$out = array();
		// Colours.
		// "white" / "black" as keywords only, not inside names such as var(--white).
		if ( preg_match_all( '/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)|(?<![\w-])(?:white|black)(?![\w-])/i', $value, $m ) && 0 !== strpos( $prop, '--' ) ) {
			foreach ( $m[0] as $col ) {
				$rgb = self::rgb( $col );
				if ( ! $rgb || ! $palette ) {
					continue;
				}
				$best = '';
				$bd   = 1e9;
				foreach ( $palette as $n => $c ) {
					$d = sqrt( pow( $c[0] - $rgb[0], 2 ) + pow( $c[1] - $rgb[1], 2 ) + pow( $c[2] - $rgb[2], 2 ) );
					if ( $d < $bd ) {
						$bd   = $d;
						$best = $n;
					}
				}
				$a     = self::alpha( $col );
				if ( null !== $a && $a < 1 ) {
					$pct  = (int) max( 10, min( 90, round( $a * 10 ) * 10 ) );
					$tok  = $best . '-trans-' . $pct;
					if ( isset( $vars[ $tok ] ) ) {
						$out[] = array( 'found' => $col, 'use' => 'var(--' . $tok . ')', 'match' => $bd < 40 ? 'close' : 'nearest token', 'reason' => 'Transparent colours come from the -trans-* tokens.' );
						continue;
					}
				}
				$alpha = preg_match( '/(rgba|hsla)\(|\/\s*[\d.]+%?\s*\)|#[0-9a-f]{8}\b/i', $col ) ? ' (it has transparency: use a -trans-* token or color-mix(in oklch, var(--' . $best . ') 50%, transparent))' : '';
				$out[] = array(
					'found'   => $col,
					'use'     => 'var(--' . $best . ')',
					'match'   => $bd < 4 ? 'exact' : ( $bd < 40 ? 'close' : 'nearest token (the colour itself is not in the palette)' ),
					'reason'  => 'Colours should come from the palette' . $alpha . '.',
				);
			}
		}
		// Lengths.
		$map = array(
			'/^(padding|margin|gap|row-gap|column-gap|inset|top|right|bottom|left)(-(block|inline|top|right|bottom|left)(-(start|end))?)?$/' => array( 'space', 'section' ),
			'/^font-size$/'     => array( 'text' ),
			'/^(border-radius|border-(top|bottom)-(left|right)-radius)$/' => array( 'radius' ),
		);
		foreach ( $map as $re => $scale_keys ) {
			if ( ! preg_match( $re, $prop ) || false !== strpos( $value, 'var(' ) ) {
				continue;
			}
			if ( ! preg_match_all( '/(?<![\w.#-])(\d*\.?\d+)(px|rem)\b/', $value, $lm, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $lm as $l ) {
				$px = (float) $l[1] * ( 'rem' === $l[2] ? Defaults::root_px() : 1 );
				if ( $px <= 1 ) {
					continue;
				}
				$best = '';
				$bd   = 1e9;
				foreach ( $scale_keys as $sk ) {
					foreach ( $scales[ $sk ] as $n => $v ) {
						if ( 'section' === $sk && $px < 48 ) {
							continue;
						}
						$d = abs( $v - $px );
						if ( $d < $bd ) {
							$bd   = $d;
							$best = $n;
						}
					}
				}
				if ( '' === $best ) {
					continue;
				}
				$out[] = array(
					'found'  => $l[0],
					'use'    => 'var(--' . $best . ')',
					'match'  => $bd / max( 1, $px ) < 0.08 ? 'close' : 'nearest step',
					'reason' => 'font-size' === $prop ? 'Font sizes come from the fluid type scale (or leave headings and body text to the global typography).' : ( 0 === strpos( $prop, 'border' ) ? 'Corner radius comes from the radius scale.' : 'Spacing comes from the fluid space scale (sections are padded automatically).' ),
				);
			}
		}
		// Shadows, widths, fonts.
		if ( 'box-shadow' === $prop && 'none' !== strtolower( $value ) && false === strpos( $value, 'var(' ) ) {
			$out[] = array( 'found' => $value, 'use' => 'var(--shadow-1) … var(--shadow-5)', 'match' => 'pick the level', 'reason' => 'Shadows come from the elevation scale.' );
		}
		if ( in_array( $prop, array( 'max-width', 'width', 'max-inline-size', 'inline-size' ), true ) && isset( $vars['content-width'] ) && preg_match( '/^(\d+)px$/', trim( $value ), $wm ) ) {
			$cw = self::px_range( $vars['content-width'] );
			if ( $cw && abs( $cw[1] - (float) $wm[1] ) < 60 ) {
				$out[] = array( 'found' => $value, 'use' => 'var(--content-width)', 'match' => 'close', 'reason' => 'Matches the site’s content width.' );
			}
		}
		if ( 'font-family' === $prop && false === strpos( $value, 'var(' ) && ( isset( $vars['text-font-family'] ) || isset( $vars['heading-font-family'] ) ) ) {
			$out[] = array( 'found' => $value, 'use' => 'var(--text-font-family) or var(--heading-font-family)', 'match' => 'check', 'reason' => 'Fonts are set globally; usually leave font-family out.' );
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Guidance in other tools
	// ------------------------------------------------------------------

	/**
	 * MCP server instructions: append the pointer.
	 *
	 * @param array $config Default server config.
	 * @return array
	 */
	public function server_config( $config ) {
		if ( is_array( $config ) ) {
			$desc                        = isset( $config['server_description'] ) ? (string) $config['server_description'] : '';
			$config['server_description'] = trim( $desc . "\n\n" . self::pointer() );
		}
		return $config;
	}

	/**
	 * Bricks orientation (detailed): append the TokenForge rules.
	 *
	 * @param string $text Markdown.
	 * @return string
	 */
	public function bricks_start_here( $text ) {
		return rtrim( (string) $text ) . "\n\n## TokenForge (this site’s CSS framework)\n\n" . self::pointer() . "\n\n- Put TokenForge class names (btn--primary, card, section--l, bg--dark, grid-3 …) directly in the HTML class attribute; they already exist as global classes, so send no CSS for them and never recreate them.\n- In your own classes use var(--tokens) for every colour, size, spacing, radius and shadow.\n- Sections already get gutters and padding, and stacked children get automatic gaps: do not add margins or section padding unless the design needs a different size.\n- Headings and body text are styled globally: do not set font-family, font-size or line-height on them.\n";
	}

	/**
	 * Enrich Bricks' start-here (compact) and design context results.
	 *
	 * @param mixed  $result Result.
	 * @param string $name   Ability name.
	 * @return mixed
	 */
	public function enrich_result( $result, $name ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}
		if ( 'bricks/start-here' === $name && isset( $result['orientation'] ) && is_string( $result['orientation'] ) && false === strpos( $result['orientation'], 'TokenForge' ) ) {
			$result['orientation'] .= "\n\n" . self::pointer();
		}
		if ( 'bricks/get-design-context' === $name && ! isset( $result['tokenforge'] ) ) {
			$t = self::tokens();
			$result['tokenforge'] = array(
				'about'     => self::pointer(),
				'guideTool' => 'tokenforge-get-framework',
				'rules'     => array(
					'Use TokenForge classes in HTML as they are (btn--primary, card, section--l, bg--dark, grid-3, gap-m, text--l, h2); do not send CSS for them or recreate them.',
					'Use var(--tokens) for every colour, font size, spacing, radius, shadow and width in custom CSS.',
					'Sections get gutters and padding, stacked children get gaps, headings and text are styled globally.',
				),
				'colors'    => array_keys( $t['colors'] ),
				'space'     => $t['space'],
				'text'      => $t['text'],
				'headings'  => $t['headings'],
			);
		}
		return $result;
	}
}
