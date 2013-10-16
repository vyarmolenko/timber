<?php

class TimberLoader {

	var $locations;

    const CACHEGROUP = 'timberloader';
    private $_cache_modes = array( 'none', 'transient', 'site-transient', 'cache' );

	function __construct($caller = false) {
		$this->locations = $this->get_locations($caller);
	}

	function render( $file, $data = null, $expires = false, $cache_mode = 'cache' ) {
        // Different $expires if user is anonymous or logged in
        if ( is_array( $expires ) ) {
            if ( is_user_logged_in() && isset( $expires[1] ) )
                $expires = $expires[1];
            else
                $expires = $expires[0];
        }

        if ( 'none' == $cache_mode )
            $expires = false;

        if ( false !== $expires && empty( $expires ) )
            $expires = 0;

        if ( !in_array( $cache_mode, $this->_cache_modes ) )
            $cache_mode = 'cache';

        ksort( $data );
        $key = md5( $file . json_encode( $data ) );

        $output = false;
        if ( false !== $expires )
            $output = $this->_cache_get( $key, $cache_mode );

        if ( false === $output || null === $output ) {
            $twig = $this->get_twig();
            $output = $twig->render($file, $data);
        }

        if ( false !== $output && false !== $expires )
            $this->_cache_set( $key, $output, $expires, $cache_mode );

        return $output;

	}

        private function _cache_get ( $key, $cache_mode = 'cache' ) {
            $object_cache = false;

            if ( isset( $GLOBALS[ 'wp_object_cache' ] ) && is_object( $GLOBALS[ 'wp_object_cache' ] ) )
                $object_cache = true;

            if ( !in_array( $cache_mode, $this->_cache_modes ) )
                $cache_mode = 'cache';

            $value = null;

            if ( 'transient' == $cache_mode )
                $value = get_transient( self::CACHEGROUP . '_' . $key );
            elseif ( 'site-transient' == $cache_mode )
                $value = get_site_transient( self::CACHEGROUP . '_' . $key );
            elseif ( 'cache' == $cache_mode && $object_cache )
                $value = wp_cache_get( $key, self::CACHEGROUP );

            return $value;
        }

        private function _cache_set( $key, $value, $expires = 0, $cache_mode = 'cache' ) {
            $object_cache = false;

            if ( isset( $GLOBALS[ 'wp_object_cache' ] ) && is_object( $GLOBALS[ 'wp_object_cache' ] ) )
                $object_cache = true;

            if ( (int) $expires < 1 )
                $expires = 0;

            if ( !in_array( $cache_mode, $this->_cache_modes ) )
                $cache_mode = 'cache';

            if ( 'transient' == $cache_mode )
                set_transient( self::CACHEGROUP . '_' . $key, $value, $expires );
            elseif ( 'site-transient' == $cache_mode )
                set_site_transient( self::CACHEGROUP . '_' . $key, $value, $expires );
            elseif ( 'cache' == $cache_mode && $object_cache )
                wp_cache_set( $key, self::CACHEGROUP, $expires );

            return $value;
        }

	function choose_template($filenames) {
		if (is_array($filenames)) {
			/* its an array so we have to figure out which one the dev wants */
			foreach ($filenames as $filename) {
				if ($this->template_exists($filename)) {
					return $filename;
				}
			}
			return false;
		}
		return $filenames;
	}

	function template_exists($file) {
		foreach ($this->locations as $dir) {
			$look_for = trailingslashit($dir) . $file;
			if (file_exists($look_for)) {
				return true;
			}
		}
		return false;
	}

	function get_locations_theme() {
		$theme_locs = array();
		$child_loc = get_stylesheet_directory();
		$parent_loc = get_template_directory();
		$theme_locs[] = $child_loc;
		$theme_locs[] = trailingslashit($child_loc) . trailingslashit(Timber::$dirname);
		if ($child_loc != $parent_loc) {
			$theme_locs[] = $parent_loc;
			$theme_locs[] = trailingslashit($parent_loc) . trailingslashit(Timber::$dirname);
		}
		//now make sure theres a trailing slash on everything
		foreach ($theme_locs as &$tl) {
			$tl = trailingslashit($tl);
		}
		return $theme_locs;
	}

	function get_locations_user() {
		$locs = array();
		if (isset(Timber::$locations)) {
			if (is_string(Timber::$locations)) {
				Timber::$locations = array(Timber::$locations);
			}
			foreach (Timber::$locations as $tloc) {
				$tloc = realpath($tloc);
				if (is_dir($tloc)) {
					$locs[] = $tloc;
				}
			}
		}
		return $locs;
	}

	function get_locations_caller($caller = false) {
		$locs = array();
		if ($caller && is_string($caller)) {
			$caller = trailingslashit($caller);
			if (is_dir($caller)) {
				$locs[] = $caller;
			}
			$caller_sub = $caller . trailingslashit(Timber::$dirname);
			if (is_dir($caller_sub)) {
				$locs[] = $caller_sub;
			}
		}
		return $locs;
	}

	function get_locations($caller = false) {
		//prioirty: user locations, caller (but not theme), child theme, parent theme, caller
		$locs = array();
		$locs = array_merge($locs, $this->get_locations_user());
		$locs = array_merge($locs, $this->get_locations_caller($caller));
		//remove themes from caller
		$locs = array_diff($locs, $this->get_locations_theme());
		$locs = array_merge($locs, $this->get_locations_theme());
		$locs = array_merge($locs, $this->get_locations_caller($caller));
		$locs = array_unique($locs);
		return $locs;
	}

	function get_loader() {
		$loaders = array();
		foreach ($this->locations as $loc) {
			$loc = realpath($loc);
			if (is_dir($loc)) {
				$loc = realpath($loc);
				$loaders[] = new Twig_Loader_Filesystem($loc);
			} else {
				//error_log($loc.' is not a directory');
			}
		}
		$loader = new Twig_Loader_Chain($loaders);
		return $loader;
	}

	function get_twig() {
		$loader_loc = trailingslashit(TIMBER_LOC) . 'Twig/lib/Twig/Autoloader.php';
		require_once($loader_loc);
		Twig_Autoloader::register();

		$loader = $this->get_loader();
		$params = array('debug' => WP_DEBUG, 'autoescape' => false);
		if (isset(Timber::$autoescape)){
			$params['autoescape'] = Timber::$autoescape;
		}
		if (Timber::$cache) {
			$params['cache'] = TIMBER_LOC . '/twig-cache';
		}
		$twig = new Twig_Environment($loader, $params);
		$twig->addExtension(new Twig_Extension_Debug());
		$twig = apply_filters('twig_apply_filters', $twig);
		return $twig;
	}
}