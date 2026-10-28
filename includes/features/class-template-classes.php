<?php
/**
 * Keep remote Bricks templates' original classes.
 *
 * When Bricks imports a remote template it reuses any local global class that
 * has the same *id*, even if you have since renamed that class locally. So a
 * template that used ".card" silently arrives wearing your renamed ".my-card".
 *
 * This filter looks at the remote template data before the builder sees it and,
 * only for classes whose id clashes with a local class of a DIFFERENT name,
 * gives the template class a new stable id (and updates every reference). Bricks
 * then does the right thing on its own: reuse a local class with the original
 * name, or import the original class fresh. Classes with no conflict are left
 * untouched, and the new ids are deterministic, so importing the same template
 * twice doesn't create duplicates.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Remote-template class id de-confliction.
 */
class Template_Classes {

	/**
	 * Filter callback for 'bricks/get_remote_templates_data'.
	 *
	 * @param mixed $data Remote templates data.
	 * @return mixed
	 */
	public static function filter( $data ) {
		if ( ! is_array( $data ) || empty( $data['templates'] ) || ! is_array( $data['templates'] ) ) {
			return $data;
		}
		$local = array(); // id => name
		foreach ( (array) get_option( 'bricks_global_classes', array() ) as $c ) {
			if ( isset( $c['id'], $c['name'] ) ) {
				$local[ (string) $c['id'] ] = (string) $c['name'];
			}
		}
		foreach ( $data['templates'] as &$template ) {
			if ( is_array( $template ) ) {
				$template = self::fix_template( $template, $local );
			}
		}
		unset( $template );
		return $data;
	}

	/**
	 * De-conflict one template.
	 *
	 * @param array $template Template data.
	 * @param array $local    Local classes id => name (extended with new ids).
	 * @return array
	 */
	public static function fix_template( $template, &$local ) {
		if ( empty( $template['global_classes'] ) || ! is_array( $template['global_classes'] ) ) {
			return $template;
		}
		$map = array();
		foreach ( $template['global_classes'] as &$class ) {
			if ( ! isset( $class['id'], $class['name'] ) ) {
				continue;
			}
			$id   = (string) $class['id'];
			$name = (string) $class['name'];
			if ( ! isset( $local[ $id ] ) || $local[ $id ] === $name ) {
				continue; // no clash, or the same class, Bricks' reuse is correct
			}
			$new = self::stable_id( $id, $name, $local );
			$map[ $id ]  = $new;
			$class['id'] = $new;
			if ( isset( $class['_mappedId'] ) ) {
				$class['_mappedId'] = $new;
			}
		}
		unset( $class );

		if ( $map ) {
			$props = self::component_class_props( $template );
			foreach ( $template as $key => &$value ) {
				if ( 'global_classes' !== $key && is_array( $value ) ) {
					$value = self::remap( $value, $map, $props );
				}
			}
			unset( $value );
		}
		return $template;
	}

	/**
	 * A deterministic replacement id that is free locally (or already holds a
	 * class with this exact name, from a previous import).
	 *
	 * @param string $id    Original id.
	 * @param string $name  Class name.
	 * @param array  $local Local classes id => name (updated).
	 * @return string
	 */
	private static function stable_id( $id, $name, &$local ) {
		for ( $n = 0; $n < 50; $n++ ) {
			$cand = 'tf' . substr( md5( $id . '|' . $name . '|' . $n ), 0, 6 );
			if ( ! isset( $local[ $cand ] ) || $local[ $cand ] === $name ) {
				$local[ $cand ] = $name;
				return $cand;
			}
		}
		$cand           = 'tf' . substr( md5( uniqid( $id, true ) ), 0, 8 );
		$local[ $cand ] = $name;
		return $cand;
	}

	/**
	 * Component property ids of type "class", per component id.
	 *
	 * @param array $template Template data.
	 * @return array cid => [ property ids ]
	 */
	private static function component_class_props( $template ) {
		$out = array();
		foreach ( (array) ( $template['components'] ?? array() ) as $component ) {
			$cid = (string) ( $component['id'] ?? '' );
			if ( '' === $cid ) {
				continue;
			}
			foreach ( (array) ( $component['properties'] ?? array() ) as $p ) {
				if ( 'class' === ( $p['type'] ?? '' ) && ! empty( $p['id'] ) ) {
					$out[ $cid ][] = (string) $p['id'];
				}
			}
		}
		return $out;
	}

	/**
	 * Replace class ids in every place a class reference can live: element
	 * settings (_cssGlobalClasses / _cssGlobalClassesProps), component
	 * definitions' class properties, and component instances' class property
	 * values. Walks the whole structure (elements can be nested).
	 *
	 * @param array $node  Any template sub-tree.
	 * @param array $map   Old id => new id.
	 * @param array $props Component class property ids (cid => ids).
	 * @return array
	 */
	private static function remap( $node, $map, $props ) {
		// Component definition: class-type properties.
		if ( isset( $node['properties'] ) && is_array( $node['properties'] ) && isset( $node['elements'] ) ) {
			foreach ( $node['properties'] as &$p ) {
				if ( is_array( $p ) && 'class' === ( $p['type'] ?? '' ) ) {
					foreach ( array( 'default', 'value' ) as $k ) {
						if ( isset( $p[ $k ] ) ) {
							$p[ $k ] = self::swap( $p[ $k ], $map );
						}
					}
					if ( ! empty( $p['options'] ) && is_array( $p['options'] ) ) {
						foreach ( $p['options'] as &$o ) {
							if ( isset( $o['value'] ) ) {
								$o['value'] = self::swap( $o['value'], $map );
							}
						}
						unset( $o );
					}
				}
			}
			unset( $p );
		}
		// Component instance: values of that component's class properties.
		if ( ! empty( $node['cid'] ) && isset( $node['properties'] ) && is_array( $node['properties'] ) ) {
			foreach ( $props[ (string) $node['cid'] ] ?? array() as $pid ) {
				if ( isset( $node['properties'][ $pid ] ) ) {
					$node['properties'][ $pid ] = self::swap( $node['properties'][ $pid ], $map );
				}
			}
		}
		foreach ( $node as $key => &$child ) {
			if ( '_cssGlobalClasses' === $key || '_cssGlobalClassesProps' === $key ) {
				$child = self::swap( $child, $map );
			} elseif ( is_array( $child ) ) {
				$child = self::remap( $child, $map, $props );
			}
		}
		unset( $child );
		return $node;
	}

	/**
	 * Map ids in a scalar or (nested) array of ids.
	 *
	 * @param mixed $value Value.
	 * @param array $map   Old id => new id.
	 * @return mixed
	 */
	private static function swap( $value, $map ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::swap( $v, $map );
			}
			return $value;
		}
		return ( is_string( $value ) && isset( $map[ $value ] ) ) ? $map[ $value ] : $value;
	}
}
