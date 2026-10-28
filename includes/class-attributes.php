<?php
/**
 * Data attributes: the registry (all(), the one source for the builder's suggestions and tooltips,
 * the Cheatsheet, the typo check and the AI lookup) and the CSS of the attributes.
 * The script ones (copy, tooltip text for screen readers, filter) are loaded by Features\Attr_Front.
 *
 * Most rules are wrapped in :where() (zero specificity), so a class, a builder style or your own CSS wins.
 * Not those: stack (!important inside its query, because the builder writes the direction with an id
 * selector), space padding (0,2,0, so it beats a theme style but not an element's own style settings) and
 * the filter hiding rule (!important).
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * The TokenForge data attributes.
 */
class Attributes {

	/** Allowed values per attribute. */
	const CLAMP = array( '1', '2', '3', '4', '5', '6' );
	const RATIO = array( '1/1', '4/3', '3/2', '16/9', '21/9', '3/4', '2/3', '9/16' );
	const STATE = array( 'open', 'active', 'disabled' );
	// Breakpoint names are the SCSS settings' (Scss::breakpoints(): xs 478, s 767, m 991, l 1279 px by default).
	const STACK   = array( 'xs', 's', 'm', 'l' );
	const SPACE   = array( 's', 'm', 'l' );
	const SURFACE = array( '1', '2', '3' );
	const REVEAL  = array( 'fade', 'up' );

	/** Attributes that other features already use (form extras): never flagged as misspelt. */
	const OTHER = array( 'data-tf-form', 'data-tf-step', 'data-tf-steps', 'data-tf-step-show', 'data-tf-step-current', 'data-tf-show', 'data-tf-req', 'data-tf-require', 'data-tf-calc', 'data-tf-save', 'data-tf-skip', 'data-tf-off', 'data-tf-fp', 'data-tf-a11y', 'data-tf-look', 'data-tf-mask', 'data-tf-msg', 'data-tf-counter', 'data-tf-date', 'data-tf-submit', 'data-tf-cart', 'data-tf-live', 'data-tf-form-id', 'data-tf-fpre', 'data-tf-ui', 'data-tf-badge' );

	/**
	 * Name => [ allowed values, copy text, plain-words description (also the tooltip) ].
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'data-tf-clamp' => array( self::CLAMP, 'data-tf-clamp="3"', 'Cuts the text after 1 to 6 lines and ends it with an ellipsis (use it on an element without padding, or the next line shows a sliver). The text is still in the page for screen readers, so use it only where the full text is available elsewhere, such as a card that links to the full page. It only adds the cut: it never changes the font, colour or size you set, and anything you set on the element wins.' ),
			'data-tf-ratio' => array( self::RATIO, 'data-tf-ratio="16/9"', 'Gives the element a fixed shape (1/1, 4/3, 3/2, 16/9, 21/9, 3/4, 2/3 or 9/16). On an image or video it also crops to fill. It never changes a width, height or ratio you set yourself.' ),
			'data-tf-state' => array( self::STATE, 'data-tf-state="open"', 'Marks an element as open, active or disabled so your CSS and recipes can style that state. disabled is only a look and a mouse block: it dims the element and ignores mouse clicks, but the keyboard can still reach and use it and screen readers are not told. To block the keyboard too, use a real disabled control or the inert attribute. It never changes a colour or size you set.' ),
			'data-tf-decorative' => array( array(), 'data-tf-decorative', 'Says this element is only decoration. On the page it gets aria-hidden="true" so screen readers skip it; an image inside gets an empty alt; an svg also gets focusable="false". Do not put it on, or around, a link, button or field. It takes no value and adds no styles.' ),
			'data-tf-stack' => array( self::STACK, 'data-tf-stack="m"', 'A row that becomes a column on screens narrower than the breakpoint you pick (xs 478, s 767, m 991 or l 1279 pixels by default; the numbers come from your breakpoint settings). Put it on a container that is a row or a grid. Above that width nothing changes. Children keep any width you gave them, so set it to 100% for small screens.' ),
			'data-tf-space' => array( self::SPACE, 'data-tf-space="m"', 'Spacing from your scale without a class per size (s, m or l). On a section or container it sets the space above and below; on one that also has data-tf-stack it sets the gap between the children instead. A spacing set in the element\'s own style settings still wins; a class on it does not.' ),
			'data-tf-surface' => array( self::SURFACE, 'data-tf-surface="2"', 'A raised panel: a background, a thin border and readable text from your colour tokens, light and dark included. 1 is the quietest level and 3 the strongest. This is not the textures from Surfaces and Overlays. It never changes a colour you set yourself.' ),
			'data-tf-reveal' => array( self::REVEAL, 'data-tf-reveal="up"', 'Shows the element when it scrolls into view, once: fade only fades it in, up fades it in and slides it up into place. It uses the Motion effects, so Motion, Visible effects must be on; with that off the element simply shows. Visitors who ask for less motion see no effect.' ),
			'data-tf-copy' => array( array(), 'data-tf-copy="hello@example.com"', 'Makes a button or link copy text when clicked: the value you type, or the element\'s own text when the value is empty. The visitor sees "Copied" for a moment and a screen reader says it. Needs the small TokenForge script, which loads only on pages that use it.' ),
			'data-tf-tooltip' => array( array(), 'data-tf-tooltip="Opens in a new tab"', 'Shows the text in a small bubble above the element on hover and on keyboard focus (Esc hides it); near the edge of a small screen the bubble moves sideways to stay in view. Screen readers read it as the description. Plain text only. Use it on a link or button, or on the box around a field (not on an image or a field itself, which cannot draw a bubble); on other elements add tabindex="0", or keyboard users never see it. The bubble is drawn with the element\'s ::before.' ),
			'data-tf-filter-group' => array( array(), 'data-tf-filter-group', 'Put it on the box that holds both the filter buttons and the list. Items show only when their data-tf-term matches the chosen button\'s data-tf-filter. Needs the small TokenForge script; without it, or with no button chosen, every item shows.' ),
			'data-tf-filter' => array( array(), 'data-tf-filter="design"', 'On a button inside a filter group: the term it shows. Use all to show every item again. Use the same words as the items\' data-tf-term.' ),
			'data-tf-term' => array( array(), 'data-tf-term="design web"', 'On each list item inside a filter group: its terms, separated by spaces. Dynamic data such as the item\'s category slugs fills it in a loop. Items added after the page loads are filtered on the next click.' ),
		);
	}

	/**
	 * Attributes other features set or read: name => [ copy text, plain-words description ].
	 * The form ones are written by the builder's form controls; the rest you type yourself.
	 *
	 * @return array
	 */
	public static function others() {
		return array(
			'data-scheme'        => array( 'data-scheme="dark"', 'Forces light or dark colours for this element and everything inside it, whatever the visitor chose. Values: light, dark.', array( 'light', 'dark' ) ),
			'data-scheme-toggle' => array( 'data-scheme-toggle', 'On any button: clicking it switches the page between light and dark (same as .scheme-toggle).' ),
			'data-tf-form'       => array( 'data-tf-form', 'Marks a form as a TokenForge form, so steps, conditions, calculations and saved answers work in it. The form controls in the builder set it for you.' ),
			'data-tf-steps'      => array( 'data-tf-steps', 'On the form: the step settings and the summary shown before sending. Set by the form controls in the builder.' ),
			'data-tf-step'       => array( 'data-tf-step="Contact"', 'On a field wrapper: a new step starts here, with an optional title.' ),
			'data-tf-step-show'  => array( 'data-tf-step-show', 'On a field wrapper that starts a step: the condition that decides whether this step is shown. Set by the form controls in the builder.' ),
			'data-tf-show'       => array( 'data-tf-show', 'On a field wrapper: shows the field only when another field has a given answer. Set by the form controls in the builder.' ),
			'data-tf-require'    => array( 'data-tf-require', 'On a field wrapper: makes the field required only when another field has a given answer. Set by the form controls in the builder.' ),
			'data-tf-calc'       => array( 'data-tf-calc', 'On a field wrapper: shows a total worked out from other fields. Set by the form controls in the builder.' ),
			'data-tf-save'       => array( 'data-tf-save', 'On the form: keeps the visitor answers in their own browser so they come back to them later.' ),
		);
	}

	/**
	 * The names the Data attributes screen lists, as [ n, d ] (the Cheatsheet's attribute rows that start data-tf- or data-scheme, without the 500 KB cheatsheet).
	 *
	 * @return array
	 */
	public static function list() {
		$out = array();
		foreach ( self::others() as $n => $a ) {
			$out[ $n ] = $a[1];
		}
		foreach ( self::all() as $n => $a ) {
			$out[ $n ] = $a[2] . ( $a[0] ? ' Values: ' . implode( ', ', $a[0] ) . '.' : '' );
		}
		$out['data-tf-a11y'] = 'Set to off on an element: the automatic accessibility attributes leave it and everything inside it exactly as you built it.';
		$list = array();
		foreach ( $out as $n => $d ) {
			if ( preg_match( '/^data-(tf-|scheme)/', $n ) ) {
				$list[] = array( 'n' => $n, 'd' => $d );
			}
		}
		return $list;
	}
	/**
	 * Check a list of data-tf-* attribute names: the unknown ones, each with a suggestion.
	 *
	 * @param array $names Attribute names (anything not starting with data-tf- is ignored).
	 * @return array List of [ attribute, didYouMean ].
	 */
	public static function check_names( array $names ) {
		$known = array_merge( array_keys( self::all() ), self::OTHER, array_keys( self::others() ) );
		$out   = array();
		$seen  = 0;
		foreach ( $names as $n ) {
			if ( ! is_scalar( $n ) || $seen >= 200 ) {
				continue;
			}
			$n = strtolower( trim( (string) $n ) );
			if ( strlen( $n ) > 64 || 0 !== strpos( $n, 'data-tf-' ) || in_array( $n, $known, true ) || isset( $out[ $n ] ) ) {
				continue;
			}
			$seen++; // at most 200 names are compared.
			$best = '';
			$bd   = 99;
			foreach ( $known as $k ) {
				$d = levenshtein( $n, $k );
				if ( $d < $bd ) {
					$bd   = $d;
					$best = $k;
				}
			}
			$out[ $n ] = array( 'attribute' => $n, 'didYouMean' => $bd <= 3 ? $best : '' );
		}
		return array_values( $out );
	}

	/**
	 * The CSS.
	 *
	 * @param bool $prune Leave out the rules of the attributes the last scan did not find (the visitors' stylesheet only,
	 *                    and only while "Leave out unused utility classes" has full scans to go by).
	 * @return string
	 */
	public static function build( $prune = false ) {
		$used = $prune ? self::used() : null;
		// The "Always keep" list may name attributes the scan cannot see (theme templates, shortcodes, scripts): data-tf-tooltip or a prefix such as data-tf-*.
		$keep = $prune ? array_filter( Class_Usage::always(), function ( $r ) { return 0 === strpos( $r, "data-" ); } ) : array();
		$has  = function ( $names ) use ( $used, $keep ) {
			if ( null === $used || array_intersect_key( $used, array_flip( (array) $names ) ) ) {
				return true;
			}
			foreach ( (array) $names as $n ) {
				foreach ( $keep as $r ) {
					if ( $r === $n || ( "*" === substr( $r, -1 ) && 0 === strpos( $n, rtrim( $r, "*" ) ) ) ) {
						return true;
					}
				}
			}
			return false;
		};
		$css = '';
		if ( $has( 'data-tf-clamp' ) ) {
			foreach ( self::CLAMP as $n ) {
				$css .= ":where([data-tf-clamp=\"$n\"]){display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:$n;line-clamp:$n;overflow:hidden}";
			}
		}
		if ( $has( 'data-tf-ratio' ) ) {
			foreach ( self::RATIO as $r ) {
				$css .= ":where([data-tf-ratio=\"$r\"]){aspect-ratio:$r}";
			}
			$css .= ':where(img[data-tf-ratio],video[data-tf-ratio]){object-fit:cover;max-inline-size:100%}';
		}
		if ( $has( 'data-tf-state' ) ) {
			$css .= ':where([data-tf-state="disabled"]){opacity:.5;pointer-events:none;cursor:not-allowed}';
		}
		// Stack: the builder writes flex-direction and grid columns with an id selector, so these two need !important (only inside the query).
		$bp = Scss::breakpoints();
		if ( $has( 'data-tf-stack' ) ) {
			foreach ( self::STACK as $n ) {
				$css .= '@media (max-width:' . (int) $bp[ $n ] . "px){:where([data-tf-stack=\"$n\"]){flex-direction:column!important;grid-template-columns:1fr!important}}";
			}
		}
		if ( $has( 'data-tf-space' ) ) {
			foreach ( self::SPACE as $n ) {
				// Plain selector (0,2,0): beats a theme style such as .brxe-section, loses to a per-element builder setting (#brxe-id).
				$css .= "[data-tf-space=\"$n\"]:not([data-tf-stack]){padding-block:var(--section-space-$n)}:where([data-tf-stack][data-tf-space=\"$n\"]){gap:var(--space-$n)}";
			}
		}
		// Surface: the scheme tokens flip in dark mode by themselves. Level 3 mixes only 8%: in dark mode --bg-dark is the light
		// colour, so more would lower the text contrast (measured 4.84:1 on a saturated palette; 12% fell to 4.51).
		if ( $has( 'data-tf-surface' ) ) {
			$css .= ':where([data-tf-surface]){border:1px solid color-mix(in srgb,var(--bg-dark) 15%,transparent);color:var(--text-dark)}';
			$css .= ':where([data-tf-surface="1"]){background-color:var(--bg-ultra-light)}:where([data-tf-surface="2"]){background-color:var(--bg-light)}:where([data-tf-surface="3"]){background-color:color-mix(in srgb,var(--bg-light),var(--bg-dark) 8%)}';
		}
		// Tooltip and copy feedback (they share selectors, so they stay together): one bubble above the host; transparent bottom border = a hoverable gap.
		// Hidden = display:none, so a bubble near the edge adds no scroll width (the fade needs allow-discrete); the script keeps it on screen with --tf-tip-shift.
		if ( $has( array( 'data-tf-tooltip', 'data-tf-copy' ) ) ) {
			$css .= ':where([data-tf-tooltip],[data-tf-copy]){position:relative}';
			$css .= ':where([data-tf-tooltip],[data-tf-copy].tf-copied)::before{content:attr(data-tf-tooltip);content:attr(data-tf-tooltip) / "";position:absolute;z-index:100;inset:auto auto 100% 50%;translate:calc(-50% + var(--tf-tip-shift,0px)) 0;box-sizing:border-box;inline-size:max-content;max-inline-size:min(20em,90vw);padding:.3em .6em;border-bottom:.4em solid transparent;border-radius:var(--radius-s,4px);background:var(--bg-ultra-dark) padding-box;color:var(--text-light);font-size:var(--text-s,.875rem);font-weight:400;line-height:1.4;letter-spacing:normal;text-align:center;text-transform:none;white-space:normal;display:none;opacity:0;pointer-events:none;transition:opacity .15s,display .15s allow-discrete}';
			$css .= ':where([data-tf-tooltip=""])::before{content:none}';
			$css .= ':where([data-tf-tooltip]:hover,[data-tf-tooltip]:focus-within)::before{display:block;opacity:1;pointer-events:auto}';
			$css .= ':where([data-tf-tooltip].tf-tip-off)::before{display:none;opacity:0;pointer-events:none}';
			$css .= ':where([data-tf-copy].tf-copied)::before{content:var(--tf-copied-text,"\2713");content:var(--tf-copied-text,"\2713") / "";display:block;opacity:1}';
			$css .= '@starting-style{:where([data-tf-tooltip]:hover,[data-tf-tooltip]:focus-within,[data-tf-copy].tf-copied)::before{opacity:0}}';
			$css .= '@media (prefers-reduced-motion:reduce){:where([data-tf-tooltip],[data-tf-copy])::before{transition:none}}';
		}
		// Filter fallback (browsers without :has()): the script marks the items itself.
		if ( $has( array( 'data-tf-filter-group', 'data-tf-term' ) ) ) {
			$css .= '[data-tf-term].tf-filter-off{display:none!important}';
		}
		return '' === $css ? '' : "\n/* --- Data attributes --- */\n" . $css . "\n";
	}

	/**
	 * The names the last attribute scan found (name => row), or null to keep every rule: "Leave out unused utility classes"
	 * is off, or the class scan or the attribute scan is missing, stopped early (a capped scan has not seen every page) or older than 0.53.3 (no 'v'; it did not read widgets and nothing kept it current).
	 *
	 * @return array|null
	 */
	private static function used() {
		$scan = Attribute_Usage::last();
		if ( ! Class_Usage::active() || empty( $scan['time'] ) || empty( $scan['v'] ) || ! empty( $scan['capped'] ) ) {
			return null; // without Class_Usage::active() the builder canvas has no full stylesheet of its own
		}
		return isset( $scan['uses'] ) && is_array( $scan['uses'] ) ? $scan['uses'] : array();
	}
}
