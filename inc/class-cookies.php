<?php

namespace HM\Limit_Login_Attempts;

class Cookies extends Plugin {

	public function load() {

		if ( get_option( 'hm_limit_login_cookies' ) ) {

			$this->handle_cookies();

			add_action( 'auth_cookie_bad_username', array( $this, 'failed_cookie' ) );
			add_action( 'auth_cookie_bad_hash', array( $this, 'failed_cookie_hash' ) );
			add_action( 'auth_cookie_valid', array( $this, 'valid_cookie' ), 10, 2 );
			add_action( 'wp_login_failed', array( $this, 'failed' ) );

		}

	}

	/**
	 * Must be called in plugin_loaded (really early) to make sure we do not allow
	 * auth cookies while locked out.
	 */
	private function handle_cookies() {
		$validation_object = Validation::get_instance();
		if ( $validation_object->is_ok_to_login() ) {
			return;
		}

		$this->clear_auth_cookie();
	}

	/**
	 * Action: failed cookie login (calls limit_login_failed())
	 * 
	 * @param $cookie_elements
	 */
	public function failed_cookie( $cookie_elements ) {
		$this->clear_auth_cookie();

		/*
		 * Invalid username gets counted every time.
		 */

		$this->failed( $cookie_elements['username'] );
	}

	/**
	 * Action: failed cookie login hash
	 *
	 * Make sure same invalid cookie doesn't get counted more than once.
	 *
	 * Requires WordPress version 3.0.0, previous versions use limit_login_failed_cookie()
	 * 
	 * @param $cookie_elements
	 */
	public function failed_cookie_hash( $cookie_elements ) {
		$this->clear_auth_cookie();

		/*
		 * Under some conditions an invalid auth cookie will be used multiple
		 * times, which results in multiple failed attempts from that one
		 * cookie.
		 *
		 * Unfortunately I've not been able to replicate this consistently and
		 * thus have not been able to make sure what the exact cause is.
		 *
		 * Probably it is because a reload of for example the admin dashboard
		 * might result in multiple requests from the browser before the invalid
		 * cookie can be cleard.
		 *
		 * Handle this by only counting the first attempt when the exact same
		 * cookie is attempted for a user.
		 */

		extract( $cookie_elements, EXTR_OVERWRITE );

		// Check if cookie is for a valid user
		$user = get_user_by( 'login', $username );
		if ( ! $user ) {
			// "shouldn't happen" for this action
			$this->failed( $username );

			return;
		}

		$previous_cookie = get_user_meta( $user->ID, 'hm_limit_login_previous_cookie', true );
		if ( $previous_cookie && $previous_cookie == $cookie_elements ) {
			// Identical cookies, ignore this attempt
			return;
		}

		// Store cookie
		if ( $previous_cookie ) {
			update_user_meta( $user->ID, 'hm_limit_login_previous_cookie', $cookie_elements );
		} else {
			add_user_meta( $user->ID, 'hm_limit_login_previous_cookie', $cookie_elements, true );
		}

		$this->failed( $username );
	}


	/*
	 * Action: successful cookie login
	 *
	 * Clear any stored user_meta.
	 *
	 * Requires WordPress version 3.0.0, not used in previous versions
	 */
	public function valid_cookie( $cookie_elements, $user ) {
		/*
		 * As all meta values get cached on user load this should not require
		 * any extra work for the common case of no stored value.
		 */

		if ( get_user_meta( $user->ID, 'hm_limit_login_previous_cookie' ) ) {
			delete_user_meta( $user->ID, 'hm_limit_login_previous_cookie' );
		}
	}

	/**
	 * Action when login attempt failed
	 *
	 * Increase nr of retries (if necessary). Reset valid value. Setup
	 * lockout if nr of retries are above threshold. And more!
	 *
	 * A note on external whitelist: retries and statistics are still counted and
	 * notifications done as usual, but no lockout is done.
	 */
	public function failed( $username ) {

		$validation_object = Validation::get_instance();

		/* if currently locked-out, do not add to retries */
		$lockouts = get_option( 'hm_limit_login_lockouts' );
		if ( ! is_array( $lockouts ) ) {
			$lockouts = array();
		}

		$lockout_method = $validation_object->get_lockout_method();

		if ( $lockout_method['ip'] ) {

			$ip = $validation_object->get_address();
			if ( isset( $lockouts[ $ip ] ) && time() < $lockouts[ $ip ] ) {
				return;
			}

			$lockout_item = $ip;

		}

		if ( $lockout_method['username'] ) {

			if ( isset( $lockouts[ $username ] ) && time() < $lockouts[ $username ] ) {
				return;
			}

			$lockout_item = $username;
		}


		/* Get the arrays with retries and retries-valid information */
		$retries = get_option( 'hm_limit_login_retries' );
		$valid   = get_option( 'hm_limit_login_retries_valid' );
		if ( ! is_array( $retries ) ) {
			$retries = array();
			add_option( 'hm_limit_login_retries', $retries, '', 'no' );
		}
		if ( ! is_array( $valid ) ) {
			$valid = array();
			add_option( 'hm_limit_login_retries_valid', $valid, '', 'no' );
		}

		/* Check validity and add one to retries */
		if ( isset( $retries[ $lockout_item ] ) && isset( $valid[ $lockout_item ] ) && time() < $valid[ $lockout_item ] ) {
			$retries[ $lockout_item ]++;
		} else {
			$retries[ $lockout_item ] = 1;
		}

		$valid[ $lockout_item ] = time() + absint( get_option( 'hm_limit_login_valid_duration' ) );

		/* lockout? */
		if ( 0 !== $retries[ $lockout_item ] % absint( get_option( 'hm_limit_login_allowed_retries' ) ) ) {

			/**
			 * Not lockout (yet!)
			 * Do housecleaning (which also saves retry/valid values).
			 */
			$this->cleanup( $retries, null, $valid );

			return;
		}

		/* lockout! */

		$retries_long = get_option( 'hm_limit_login_allowed_retries' )
			* get_option( 'hm_limit_login_allowed_lockouts' );

		$whitelisted = false;

		if ( $lockout_method['ip'] ) {

			$whitelisted = $validation_object->is_ip_whitelisted( $lockout_item );

			/*
			 * Note that retries and statistics are still counted and notifications
			 * done as usual for whitelisted ips , but no lockout is done.
			 */
			if ( $whitelisted ) {
				if ( $retries[ $lockout_item ] >= $retries_long ) {
					unset( $retries[ $lockout_item ] );
					unset( $valid[ $lockout_item ] );
				}
			}

		}

		if ( ! $whitelisted ) {
			$validation_object->lockout();

			/* setup lockout, reset retries as needed */
			if ( $retries[ $lockout_item ] >= $retries_long ) {
				/* long lockout */
				$lockouts[ $lockout_item ] = time() + get_option( 'hm_limit_login_long_duration' );
				unset( $retries[ $lockout_item ] );
				unset( $valid[ $lockout_item ] );
			} else {
				/* normal lockout */
				$lockouts[ $lockout_item ] = time() + get_option( 'hm_limit_login_lockout_duration' );
			}
		}

		/* do housecleaning and save values */
		$this->cleanup( $retries, $lockouts, $valid );

		/* do any notification */
		$notifcation_object = Notifications::get_instance();
		$notifcation_object->notify( $username );

		/* increase statistics */
		$total = get_option( 'hm_limit_login_lockouts_total' );
		if ( $total === false || ! is_numeric( $total ) ) {
			add_option( 'hm_limit_login_lockouts_total', 1, '', 'no' );
		} else {
			update_option( 'hm_limit_login_lockouts_total', $total + 1 );
		}
	}


	/* Make sure auth cookie really get cleared (for this session too) */
	private function clear_auth_cookie() {
		wp_clear_auth_cookie();

		if ( ! empty( $_COOKIE[ AUTH_COOKIE ] ) ) {
			$_COOKIE[ AUTH_COOKIE ] = '';
		}
		if ( ! empty( $_COOKIE[ SECURE_AUTH_COOKIE ] ) ) {
			$_COOKIE[ SECURE_AUTH_COOKIE ] = '';
		}
		if ( ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = '';
		}
	}


	/* Clean up old lockouts and retries, and save supplied arrays */
	public function cleanup( $retries = null, $lockouts = null, $valid = null ) {
		$now      = time();
		$lockouts = ! is_null( $lockouts ) ? $lockouts : get_option( 'hm_limit_login_lockouts' );

		/* remove old lockouts */
		if ( is_array( $lockouts ) ) {
			foreach ( $lockouts as $ip => $lockout ) {
				if ( $lockout < $now ) {
					unset( $lockouts[ $ip ] );
				}
			}
			update_option( 'hm_limit_login_lockouts', $lockouts );
		}

		/* remove retries that are no longer valid */
		$valid   = ! is_null( $valid ) ? $valid : get_option( 'hm_limit_login_retries_valid' );
		$retries = ! is_null( $retries ) ? $retries : get_option( 'hm_limit_login_retries' );
		if ( ! is_array( $valid ) || ! is_array( $retries ) ) {
			return;
		}

		foreach ( $valid as $ip => $lockout ) {
			if ( $lockout < $now ) {
				unset( $valid[ $ip ] );
				unset( $retries[ $ip ] );
			}
		}

		/* go through retries directly, if for some reason they've gone out of sync */
		foreach ( $retries as $ip => $retry ) {
			if ( ! isset( $valid[ $ip ] ) ) {
				unset( $retries[ $ip ] );
			}
		}

		update_option( 'hm_limit_login_retries', $retries );
		update_option( 'hm_limit_login_retries_valid', $valid );
	}

}
