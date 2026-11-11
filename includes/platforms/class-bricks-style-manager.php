<?php
/**
 * Bricks Style Manager sync.
 *
 * TokenForge fills the builder's Style Manager with its own scales and settings:
 *  - Typography: two scales, "text" (--text-xs … --text-xxl) and "headings"
 *    (--h6 … --h1); Spacing: one scale (--space-xs … --space-xxl). Each is a
 *    variable category with a scale config (base size, ratio, steps); its
 *    variables are the TokenForge ones (written by Bricks::sync()).
 *  - Settings: HTML font-size, min and max screen width, default mode.
 * A scale or setting an administrator changes in the Style Manager is written
 * into the matching TokenForge setting, and TokenForge rebuilds.
 *
 * Stale copies: the builder keeps the scales and settings it loaded and sends
 * them back whole on save, without reloading. Every version TokenForge writes
 * carries a stamp (the scale's tokenforgeStamp, the settings' modified time),
 * and the state keeps what each stamp held. A saved copy is compared with what
 * its own stamp held when the builder loaded it: only what differs from that is
 * an edit, so an old builder tab never undoes a newer TokenForge save.
 *
 * Only runs when Bricks is the active theme and variables are synced.
 *
 * @package TokenForge
 */

namespace TokenForge\Platforms;

use TokenForge\Css_Generator;
use TokenForge\Defaults;
use TokenForge\Modules;
use TokenForge\Scale_Engine;
use TokenForge\Scheme;
use TokenForge\Settings;
use TokenForge\Token_Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Style Manager sync.
 */
class Bricks_Style_Manager {

	const OPTION = 'bricks_style_manager';
	const CATS   = 'bricks_global_variables_categories';
	const VARS   = 'bricks_global_variables';
	const STATE  = 'tokenforge_style_manager_sync'; // What each stamp held, and the settings from before TokenForge.
	const KEEP   = 20; // Stamps remembered per part.

	/**
	 * The settings keys TokenForge looks after.
	 */
	const SM_KEYS = array( 'htmlFontSize', 'minScreenWidth', 'maxScreenWidth', 'defaultMode' );

	/**
	 * Our own write in progress.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'tokenforge_tokens_saved', array( __CLASS__, 'push' ), 20 ); // After Bricks::sync() wrote the variables.
		foreach ( array( self::OPTION, self::CATS ) as $opt ) {
			add_action( 'update_option_' . $opt, array( __CLASS__, 'on_saved' ), 20, 0 );
			add_action( 'add_option_' . $opt, array( __CLASS__, 'on_saved' ), 20, 0 );
		}
		add_action( 'update_option_' . self::VARS, array( __CLASS__, 'on_vars_saved' ), 20, 2 );
	}

	/**
	 * Is the sync on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = Settings::get_instance()->get_settings();
		return ! empty( $s['sync_bricks_vars'] ) && Bricks::is_active();
	}

	/**
	 * May the current request change TokenForge settings from the builder?
	 * The builder lets any builder user save the Style Manager; TokenForge's own
	 * settings need an administrator (and edit_css on multisite).
	 *
	 * @return bool
	 */
	public static function can_pull() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return true;
		}
		return current_user_can( 'manage_options' ) && ( ! is_multisite() || current_user_can( 'edit_css' ) );
	}

	/**
	 * The scales: category id => config. Steps run small to large, as the
	 * builder's scale generator expects.
	 *
	 * @return array
	 */
	public static function scales() {
		return array(
			'tokenforge_text'     => array(
				'label'  => 'text',
				'scope'  => 'typography',
				'prefix' => 'text-',
				'steps'  => Scale_Engine::STEPS,
				'base'   => 'm',
				'keys'   => array( 'text_base_min', 'text_ratio_min', 'text_base_max', 'text_ratio_max' ),
			),
			'tokenforge_headings' => array(
				'label'  => 'headings',
				'scope'  => 'typography',
				'prefix' => 'h',
				'steps'  => array( '6', '5', '4', '3', '2', '1' ),
				'base'   => '4',
				'keys'   => array( 'heading_base_min', 'heading_ratio_min', 'heading_base_max', 'heading_ratio_max' ),
			),
			'tokenforge_space'    => array(
				'label'  => 'spacing',
				'scope'  => 'spacing',
				'prefix' => 'space-',
				'steps'  => Scale_Engine::STEPS,
				'base'   => 'm',
				'keys'   => array( 'space_base_min', 'space_ratio_min', 'space_base_max', 'space_ratio' ),
			),
		);
	}

	/**
	 * The scale a variable belongs to, for Bricks::sync().
	 *
	 * @param string $name Variable name (no leading --).
	 * @return array|null [ category id, step relative to the base, step name ].
	 */
	public static function scale_of( $name ) {
		foreach ( self::scales() as $id => $sc ) {
			if ( 0 !== strpos( (string) $name, $sc['prefix'] ) ) {
				continue;
			}
			$i = array_search( substr( $name, strlen( $sc['prefix'] ) ), $sc['steps'], true );
			if ( false !== $i ) {
				return array( $id, $i - array_search( $sc['base'], $sc['steps'], true ), $sc['steps'][ $i ] );
			}
		}
		return null;
	}

	/**
	 * Take over scale variables the builder re-created: its "Generate
	 * variables" replaces a scale's rows with new ids. A row in one of our scale
	 * categories with one of our names gets our id back; extra copies go. A row
	 * the builder made there under another name (another prefix or step) is
	 * not TokenForge's: it moves out to the uncategorised variables, kept.
	 *
	 * @param array $vars Variables.
	 * @return array
	 */
	public static function adopt( $vars ) {
		$seen = array();
		foreach ( $vars as $v ) {
			if ( isset( $v['id'] ) && Bricks::is_ours( $v ) ) {
				$seen[ $v['id'] ] = true;
			}
		}
		$out = array();
		foreach ( $vars as $v ) {
			if ( isset( $v['category'] ) && isset( self::scales()[ $v['category'] ] ) && ! Bricks::is_ours( $v ) ) {
				$scale = isset( $v['name'] ) ? self::scale_of( $v['name'] ) : null;
				if ( $scale && $scale[0] === $v['category'] ) {
					$id = Bricks::VAR_ID_PREFIX . $v['name'];
					if ( isset( $seen[ $id ] ) ) {
						continue; // We have this one already.
					}
					$v['id']     = $id;
					$seen[ $id ] = true;
				} else {
					$v['category'] = '';
					unset( $v['scale'] );
				}
			}
			$out[] = $v;
		}
		return $out;
	}

	/**
	 * After the builder saved the variables (with its own rounding, or a copy it
	 * loaded before a TokenForge save): put TokenForge's values, ids and step
	 * order back on every TokenForge variable.
	 *
	 * @param mixed $old  Old value.
	 * @param mixed $vars New value.
	 * @return void
	 */
	public static function on_vars_saved( $old, $vars ) {
		if ( self::$busy || ! self::enabled() || ! is_array( $vars ) ) {
			return;
		}
		$want = array();
		foreach ( Token_Catalog::every_variable() as $t ) {
			if ( ! empty( $t['enabled'] ) ) {
				$want[ Bricks::VAR_ID_PREFIX . $t['name'] ] = $t['value'];
			}
		}
		$fixed = self::adopt( array_values( $vars ) );
		foreach ( $fixed as $i => $v ) {
			if ( isset( $v['id'], $want[ $v['id'] ] ) && Bricks::is_ours( $v ) && ( ! isset( $v['value'] ) || $v['value'] !== $want[ $v['id'] ] ) ) {
				$fixed[ $i ]['value'] = $want[ $v['id'] ];
			}
		}
		$fixed = Bricks::scale_order( $fixed );
		if ( array_values( $vars ) !== $fixed ) {
			self::$busy = true;
			try {
				update_option( self::VARS, $fixed );
				self::regenerate_css();
			} finally {
				self::$busy = false;
			}
		}
	}

	/**
	 * Min and max px of each step (overrides included), in step order.
	 *
	 * @param array $sc Scale config.
	 * @param array $s  Scale settings.
	 * @param array $ov Overrides.
	 * @return array[] [ [min, max], … ]
	 */
	private static function sizes( $sc, $s, $ov ) {
		list( $bmin, $rmin, $bmax, $rmax ) = array_map(
			function ( $k ) use ( $s ) {
				return (float) $s[ $k ];
			},
			$sc['keys']
		);
		$mid                               = array_search( $sc['base'], $sc['steps'], true );
		$out                               = array();
		foreach ( $sc['steps'] as $i => $step ) {
			$n     = $sc['prefix'] . $step;
			$out[] = array(
				isset( $ov[ $n ]['min'] ) ? $ov[ $n ]['min'] : $bmin * pow( $rmin, $i - $mid ),
				isset( $ov[ $n ]['max'] ) ? $ov[ $n ]['max'] : $bmax * pow( $rmax, $i - $mid ),
			);
		}
		return $out;
	}

	/**
	 * What TokenForge wants in the Style Manager now.
	 *
	 * @return array [ 'cats' => [ id => category ], 'sm' => [ key => value ] ]
	 */
	public static function desired() {
		$s    = Modules::raw( array( Scale_Engine::class, 'settings' ) );
		$ov   = Scale_Engine::overrides();
		$cat  = Settings::get_instance()->get_settings()['category_name'];
		$cats = array();
		if ( ! empty( $s['enabled'] ) ) {
			foreach ( self::scales() as $id => $sc ) {
				$manual = false;
				foreach ( $sc['steps'] as $step ) {
					$manual = $manual || isset( $ov[ $sc['prefix'] . $step ] );
				}
				$values = array();
				foreach ( self::sizes( $sc, $s, $ov ) as $i => $p ) {
					$values[] = array(
						'name' => $sc['prefix'] . $sc['steps'][ $i ],
						'min'  => round( $p[0], 2 ) . 'px',
						'max'  => round( $p[1], 2 ) . 'px',
					);
				}
				$cats[ $id ] = array(
					'id'             => $id,
					'name'           => $cat . ' ' . $sc['label'],
					'utilityClasses' => array(), // TokenForge outputs its own text-- and space classes.
					'scale'          => array(
						'scaleScope'          => $sc['scope'],
						'scaleType'           => 'custom',
						'scaleNames'          => $sc['steps'],
						'baseline'            => $sc['base'],
						'prefix'              => $sc['prefix'],
						'minFontSize'         => (float) $s[ $sc['keys'][0] ],
						'minScaleRatio'       => (float) $s[ $sc['keys'][1] ],
						'minScaleRatioSelect' => 'custom',
						'maxFontSize'         => (float) $s[ $sc['keys'][2] ],
						'maxScaleRatio'       => (float) $s[ $sc['keys'][3] ],
						'maxScaleRatioSelect' => 'custom',
						'isManual'            => $manual,
						'manualValues'        => $values,
					),
				);
			}
		}
		$d  = Modules::raw( array( Defaults::class, 'settings' ) );
		$sh = Modules::raw( array( Scheme::class, 'settings' ) );
		return array(
			'cats' => $cats,
			'sm'   => array(
				'htmlFontSize'   => ( (float) $d['root_font_size'] ) . '%',
				'minScreenWidth' => (int) $s['min_vw'],
				'maxScreenWidth' => (int) $s['max_vw'],
				'defaultMode'    => 'auto' === $sh['dark_mode'] ? $sh['default_scheme'] : 'light',
			),
		);
	}

	/**
	 * Comparable copy of the parts TokenForge looks after: [ part => [ key => value ] ],
	 * part = 'sm' or a scale category id. Step values are keyed by variable name.
	 *
	 * @param array $cats Categories (any order).
	 * @param array $sm   Style manager settings.
	 * @return array
	 */
	public static function snapshot( $cats, $sm ) {
		$out    = array();
		$scales = self::scales();
		foreach ( (array) $cats as $c ) {
			if ( ! isset( $c['id'], $scales[ $c['id'] ], $c['scale'] ) || ! is_array( $c['scale'] ) ) {
				continue;
			}
			$x     = $c['scale'];
			$sc    = $scales[ $c['id'] ];
			$ratio = function ( $sel, $val ) use ( $x ) {
				$sel = isset( $x[ $sel ] ) ? $x[ $sel ] : 'custom';
				return round( (float) ( 'custom' === $sel ? ( isset( $x[ $val ] ) ? $x[ $val ] : 0 ) : $sel ), 4 );
			};
			$mv    = isset( $x['manualValues'] ) && is_array( $x['manualValues'] ) ? array_values( $x['manualValues'] ) : array();
			$steps = array();
			foreach ( $sc['steps'] as $i => $step ) {
				$name = $sc['prefix'] . $step;
				$p    = null;
				foreach ( $mv as $j => $e ) {
					if ( is_array( $e ) && ( isset( $e['name'] ) ? $e['name'] === $name : $j === $i ) ) {
						$p = $e;
						break;
					}
				}
				$steps[ $name ] = $p ? round( isset( $p['min'] ) ? (float) $p['min'] : 0, 2 ) . '/' . round( isset( $p['max'] ) ? (float) $p['max'] : 0, 2 ) : '';
			}
			$out[ $c['id'] ] = array(
				'min'   => round( isset( $x['minFontSize'] ) ? (float) $x['minFontSize'] : 0, 4 ),
				'rmin'  => $ratio( 'minScaleRatioSelect', 'minScaleRatio' ),
				'max'   => round( isset( $x['maxFontSize'] ) ? (float) $x['maxFontSize'] : 0, 4 ),
				'rmax'  => $ratio( 'maxScaleRatioSelect', 'maxScaleRatio' ),
				'man'   => ! empty( $x['isManual'] ),
				'steps' => $steps,
			);
		}
		$sm        = is_array( $sm ) ? $sm : array();
		$out['sm'] = array();
		foreach ( self::SM_KEYS as $key ) {
			$out['sm'][ $key ] = isset( $sm[ $key ] ) ? (string) $sm[ $key ] : '';
		}
		return $out;
	}

	/**
	 * The stamp of each part as stored.
	 *
	 * @param array $cats Categories.
	 * @param array $sm   Style manager settings.
	 * @return int[] [ part => stamp ]
	 */
	public static function stamps( $cats, $sm ) {
		$out = array( 'sm' => is_array( $sm ) && isset( $sm['modified'] ) ? (int) $sm['modified'] : 0 );
		foreach ( (array) $cats as $c ) {
			if ( isset( $c['id'] ) && isset( self::scales()[ $c['id'] ] ) ) {
				$out[ $c['id'] ] = isset( $c['tokenforgeStamp'] ) ? (int) $c['tokenforgeStamp'] : 0;
			}
		}
		return $out;
	}

	/**
	 * Sync state.
	 *
	 * @return array [ 'held' => [ part => [ stamp => snapshot part ] ], 'backup' => settings before TokenForge|null ]
	 */
	public static function state() {
		$s = get_option( self::STATE, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'held'   => isset( $s['held'] ) && is_array( $s['held'] ) ? $s['held'] : array(),
			'backup' => isset( $s['backup'] ) && is_array( $s['backup'] ) ? $s['backup'] : null,
			'off'    => isset( $s['off'] ) ? (int) $s['off'] : 0, // The stamp of the settings restored when the sync went off.
		);
	}

	/**
	 * Remember what a stamp holds (newest last, KEEP per part).
	 *
	 * @param array  $state State (changed in place).
	 * @param string $part  Part.
	 * @param int    $stamp Stamp.
	 * @param array  $snap  Snapshot part.
	 * @return void
	 */
	private static function hold( &$state, $part, $stamp, $snap ) {
		if ( $stamp <= 0 ) {
			return;
		}
		$h = isset( $state['held'][ $part ] ) ? $state['held'][ $part ] : array();
		unset( $h[ $stamp ] );
		$h[ $stamp ]            = $snap;
		$state['held'][ $part ] = array_slice( $h, -self::KEEP, null, true );
	}

	/**
	 * Save the state when it changed.
	 *
	 * @param array $state New state.
	 * @param array $was   State as read.
	 * @return void
	 */
	private static function save_state( $state, $was ) {
		if ( $state !== $was ) {
			update_option( self::STATE, $state, false );
		}
	}

	/**
	 * TokenForge → Style Manager. With the sync off, our scales go and the
	 * settings from before TokenForge come back.
	 *
	 * @return void
	 */
	public static function push() {
		if ( self::$busy || ! Bricks::is_active() ) {
			return;
		}
		$on    = self::enabled();
		$cats  = get_option( self::CATS, array() );
		$cats  = is_array( $cats ) ? $cats : array();
		$sm    = get_option( self::OPTION, array() );
		$sm    = is_array( $sm ) ? $sm : array();
		$state = self::state();
		$was   = $state;
		$want  = $on ? self::desired() : array( 'cats' => array() );

		// Our scale categories: update in place, add missing, drop the ones no longer wanted.
		$new = array();
		foreach ( $cats as $c ) {
			$id = isset( $c['id'] ) ? $c['id'] : '';
			if ( ! isset( self::scales()[ $id ] ) ) {
				$new[] = $c;
			} elseif ( isset( $want['cats'][ $id ] ) ) {
				$new[] = array_merge( $c, $want['cats'][ $id ] );
				unset( $want['cats'][ $id ] );
			}
		}
		$new = array_merge( $new, array_values( $want['cats'] ) );

		// Stamps only grow: newer than anything written before.
		$now = max( time(), 1 + (int) $state['off'] );
		foreach ( $state['held'] as $h ) {
			$now = max( $now, 1 + max( array_merge( array( 0 ), array_keys( $h ) ) ) );
		}
		$vars = null;
		if ( $on ) {
			if ( null === $state['backup'] || $state['off'] ) {
				// What the site had before TokenForge (or set itself while the sync was off).
				$state['backup'] = array_intersect_key( $sm, array_flip( self::SM_KEYS ) );
				$state['off']    = 0;
			}
			$sm2 = array_merge( $sm, $want['sm'] );
		} else {
			$sm2 = $sm;
			$s   = self::stamps( array(), $sm )['sm'];
			// Switching off, or an old builder tab sent back a version TokenForge wrote: the
			// settings from before TokenForge come back. What you set after that stays.
			if ( null !== $state['backup'] && ( ! $state['off'] || isset( $state['held']['sm'][ $s ] ) ) ) {
				$sm2             = array_merge( array_diff_key( $sm, array_flip( self::SM_KEYS ) ), $state['backup'] );
				$state['off']    = $state['off'] ? $state['off'] : $now;
				$sm2['modified'] = $state['off'];
			}
			// Our variables leave the scales that are gone (when they stay, unpruned).
			$all  = get_option( self::VARS, array() );
			$vars = array();
			foreach ( is_array( $all ) ? $all : array() as $v ) {
				if ( isset( $v['category'] ) && isset( self::scales()[ $v['category'] ] ) ) {
					$v['category'] = Bricks::CATEGORY_ID;
					unset( $v['scale'] );
				}
				$vars[] = $v;
			}
			$vars = is_array( $all ) && array_values( $all ) !== $vars ? $vars : null;
		}

		// A part gets a new stamp when what it holds is not what its current stamp held
		// (a builder tab with the old stamp then counts as an older copy).
		$snap   = self::snapshot( $new, $sm2 );
		$stamps = self::stamps( $new, $sm2 );
		$parts  = $on ? array_merge( array( 'sm' ), array_keys( self::scales() ) ) : array();
		foreach ( $parts as $part ) {
			if ( ! isset( $snap[ $part ], $stamps[ $part ] ) ) {
				continue;
			}
			$s = $stamps[ $part ];
			if ( $s > 0 && isset( $state['held'][ $part ][ $s ] ) && $state['held'][ $part ][ $s ] === $snap[ $part ] ) {
				continue;
			}
			if ( 'sm' === $part ) {
				$sm2['modified'] = $now;
			} else {
				foreach ( $new as $i => $c ) {
					if ( isset( $c['id'] ) && $part === $c['id'] ) {
						$new[ $i ]['tokenforgeStamp'] = $now;
					}
				}
			}
			self::hold( $state, $part, $now, $snap[ $part ] );
		}

		self::$busy = true;
		try {
			$changed = false;
			if ( self::norm( $new ) !== self::norm( $cats ) ) {
				update_option( self::CATS, $new, false ); // The builder stores it with autoload off too.
				$changed = true;
			}
			if ( self::norm( $sm2 ) !== self::norm( $sm ) ) {
				if ( $sm2 ) {
					update_option( self::OPTION, $sm2 );
				} else {
					delete_option( self::OPTION );
				}
				$changed = true;
			}
			if ( null !== $vars ) {
				update_option( self::VARS, $vars );
				$changed = true;
			}
			self::save_state( $state, $was );
			if ( $changed ) {
				self::regenerate_css();
			}
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * A value with numbers as strings, so 16, 16.0 and "16" compare equal.
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	private static function norm( $v ) {
		if ( is_array( $v ) ) {
			return array_map( array( __CLASS__, 'norm' ), $v );
		}
		return is_int( $v ) || is_float( $v ) ? (string) ( 0 + $v ) : $v;
	}

	/**
	 * Rebuild the builder's style manager CSS file (palette and scale variables).
	 *
	 * @return void
	 */
	public static function regenerate_css() {
		if ( is_callable( array( '\Bricks\Ajax', 'generate_style_manager_css_file' ) ) ) {
			try {
				\Bricks\Ajax::generate_style_manager_css_file();
			} catch ( \Throwable $e ) {
				unset( $e ); // The builder rebuilds it on its next save.
			}
		}
	}

	/**
	 * Style Manager → TokenForge (the builder, or anything else, saved scales or settings).
	 *
	 * @return void
	 */
	public static function on_saved() {
		if ( self::$busy || ! Bricks::is_active() ) {
			return;
		}
		$state = self::state();
		if ( ! self::enabled() ) {
			if ( null !== $state['backup'] ) {
				self::push(); // Sync off: an old tab may have sent our scales or settings back.
			}
			return;
		}
		if ( ! $state['held'] ) {
			return; // Nothing pushed yet: the first push sets things up.
		}
		$was    = $state;
		$pull   = self::can_pull();
		$cats   = get_option( self::CATS, array() );
		$sm     = get_option( self::OPTION, array() );
		$snap   = self::snapshot( $cats, $sm );
		$stamps = self::stamps( $cats, $sm );
		$edits  = array();
		foreach ( $state['held'] as $part => $held ) {
			if ( ! isset( $snap[ $part ] ) || ! $held ) {
				continue; // A deleted scale: the push below puts it back.
			}
			$s = isset( $stamps[ $part ] ) ? $stamps[ $part ] : 0;
			if ( $s > 0 && ! isset( $held[ $s ] ) ) {
				continue; // A stamp too old to remember: an old copy, nothing in it counts.
			}
			// What this copy held when the builder loaded it; without a stamp, the newest version.
			$base = $s > 0 ? $held[ $s ] : end( $held );
			foreach ( $snap[ $part ] as $k => $v ) {
				if ( 'steps' === $k ) {
					foreach ( $v as $name => $pair ) {
						$was_pair = explode( '/', isset( $base['steps'][ $name ] ) ? $base['steps'][ $name ] : '/' ) + array( '', '' );
						$now_pair = explode( '/', $pair ) + array( '', '' );
						foreach ( array( 'min', 'max' ) as $j => $side ) {
							if ( $now_pair[ $j ] !== $was_pair[ $j ] ) {
								$edits[ $part ]['steps'][] = $name . '-' . $side; // Only the side you changed.
							}
						}
					}
				} elseif ( ! array_key_exists( $k, $base ) || $v !== $base[ $k ] ) {
					$edits[ $part ][ $k ] = $v;
				}
			}
			if ( $pull ) {
				self::hold( $state, $part, $s, $snap[ $part ] ); // The builder now holds this copy under this stamp.
			}
		}
		self::save_state( $state, $was );
		if ( $edits && $pull && self::apply( $edits, $snap ) ) {
			Css_Generator::get_instance()->rebuild(); // Saves, then pushes back through tokenforge_tokens_saved.
			return;
		}
		self::push(); // Put TokenForge's values back where the copy was older (or not allowed).
	}

	/**
	 * Store edits into the TokenForge settings.
	 *
	 * @param array $edits [ part => [ key => new value ] ] ('steps' => names of the steps whose values changed).
	 * @param array $snap  The whole current snapshot.
	 * @return bool Something was stored.
	 */
	public static function apply( $edits, $snap ) {
		$scale  = Modules::raw( array( Scale_Engine::class, 'settings' ) );
		$s0     = $scale;
		$def    = array();
		$sch    = array();
		$scales = self::scales();
		$num    = function ( $v ) {
			return is_numeric( $v ) && $v > 0;
		};
		$keys   = array(
			'min'  => 0,
			'rmin' => 1,
			'max'  => 2,
			'rmax' => 3,
		);
		foreach ( $edits as $part => $fields ) {
			if ( 'sm' === $part ) {
				foreach ( $fields as $field => $v ) {
					if ( 'minScreenWidth' === $field && $num( $v ) ) {
						$scale['min_vw'] = (int) $v;
					} elseif ( 'maxScreenWidth' === $field && $num( $v ) ) {
						$scale['max_vw'] = (int) $v;
					} elseif ( 'htmlFontSize' === $field && preg_match( '/^([\d.]+)\s*(%|px|rem)$/', trim( $v ), $m ) ) {
						$pct = '%' === $m[2] ? (float) $m[1] : ( 'px' === $m[2] ? (float) $m[1] / 16 * 100 : (float) $m[1] * 100 );
						if ( $pct >= 50 && $pct <= 200 ) {
							$def = array(
								'root_font_size'        => round( $pct, 2 ),
								'output_root_font_size' => true,
							);
						}
					} elseif ( 'defaultMode' === $field && in_array( $v, array( 'light', 'dark', 'auto' ), true ) ) {
						$sh = Modules::raw( array( Scheme::class, 'settings' ) );
						if ( 'auto' === $sh['dark_mode'] ) {
							$sch = array( 'default_scheme' => $v ); // With dark mode off, TokenForge has light only.
						}
					}
				}
				continue;
			}
			if ( ! isset( $scales[ $part ] ) ) {
				continue;
			}
			$sc = $scales[ $part ];
			foreach ( $fields as $field => $v ) {
				if ( isset( $keys[ $field ] ) && $num( $v ) ) {
					$scale[ $sc['keys'][ $keys[ $field ] ] ] = 0 + $v;
				}
			}
			// Manual off drops the scale's overrides. With manual on, each step you changed
			// becomes an override (or stops being one); the other steps keep theirs.
			$off     = isset( $fields['man'] ) && empty( $snap[ $part ]['man'] );
			$changed = ! empty( $snap[ $part ]['man'] ) && isset( $fields['steps'] ) ? $fields['steps'] : array();
			if ( $off || $changed ) {
				$lines = array();
				foreach ( preg_split( '/[\r\n,]+/', (string) $scale['overrides'] ) as $line ) {
					$line = trim( $line );
					$key  = preg_match( '/^([a-z0-9\-]+)-(min|max)\s*=/i', $line, $m ) ? strtolower( $m[1] . '-' . $m[2] ) : '';
					if ( '' !== $line && ! ( '' !== $key && self::in_scale( strtolower( $m[1] ), $sc ) && ( $off || in_array( $key, $changed, true ) ) ) ) {
						$lines[] = $line;
					}
				}
				if ( ! $off ) {
					$calc = self::sizes( $sc, $scale, array() );
					foreach ( $sc['steps'] as $i => $step ) {
						$name = $sc['prefix'] . $step;
						$pair = explode( '/', isset( $snap[ $part ]['steps'][ $name ] ) ? $snap[ $part ]['steps'][ $name ] : '' ) + array( '', '' );
						foreach ( array( 'min', 'max' ) as $j => $side ) {
							$val = (float) $pair[ $j ];
							if ( in_array( $name . '-' . $side, $changed, true ) && $val > 0 && abs( $val - $calc[ $i ][ $j ] ) > 0.01 ) {
								$lines[] = $name . '-' . $side . '=' . $val;
							}
						}
					}
				}
				$scale['overrides'] = implode( "\n", $lines );
			}
		}
		$stored = false;
		if ( $scale !== $s0 ) {
			Scale_Engine::save_settings( $scale );
			$stored = true;
		}
		if ( $def ) {
			Defaults::save_settings( array_replace( Modules::raw( array( Defaults::class, 'settings' ) ), $def ) );
			$stored = true;
		}
		if ( $sch ) {
			Scheme::save_settings( array_replace( Modules::raw( array( Scheme::class, 'settings' ) ), $sch ) );
			$stored = true;
		}
		return $stored;
	}

	/**
	 * Is a variable name one of this scale's steps?
	 *
	 * @param string $name Variable name.
	 * @param array  $sc   Scale config.
	 * @return bool
	 */
	private static function in_scale( $name, $sc ) {
		return 0 === strpos( $name, $sc['prefix'] ) && in_array( substr( $name, strlen( $sc['prefix'] ) ), $sc['steps'], true );
	}
}
