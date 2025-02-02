<?php
/**
 * Front face related stuff
 *
 * @since      0.0.1
 * @package    CF7_AntiSpam
 * @subpackage CF7_AntiSpam/includes
 * @author     Codekraft Studio <info@codekraft.it>
 */

/**
 * A class that handles front end related function
 */
class CF7_AntiSpam_Frontend {

	/**
	 * The ID of this plugin.
	 *
	 * @since    0.1.0
	 * @access   private
	 * @var      string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    0.1.0
	 * @access   private
	 * @var      string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * The options of this plugin.
	 *
	 * @since    0.1.0
	 * @access   public
	 * @var      array    $options    options of this plugin.
	 */
	private $options;

	/**
	 * It adds a filter to the wpcf7_form_hidden_fields hook, which is called by the Contact Form 7 plugin
	 *
	 * @param string $plugin_name The name of the plugin.
	 * @param string $version The current version number of the plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		/* the plugin options */
		$this->options = CF7_AntiSpam::get_options();
	}

	/**
	 * It takes the form elements, clones the text inputs, adds a class to the cloned inputs, and adds the cloned inputs to the form
	 *
	 * @param string $form_elements - The form elements html.
	 *
	 * @return string - The form elements.
	 */
	public function cf7a_honeypot_add( $form_elements ) {

		try {
			$html = new DOMDocument( '1.0', 'UTF-8' );
			libxml_use_internal_errors( true );
			/**
			 * The 'mb_convert_encoding' is needed for non-latin font sets / LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD avoids auto-fixes for corrupted html code
			 */
			$html->loadHTML( mb_convert_encoding( $form_elements, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			$xpath  = new DOMXpath( $html );
			$inputs = $xpath->query( '//input' );

		} catch ( Exception $e ) {
			if ( is_admin() ) {
				esc_html_e( 'I cannot parse this form correctly, please double check that the code is correct, thank you! (this message is only displayed to admins)' );
				cf7a_log( $e );
			}
			return $form_elements;
		}

		/* A list of default names for the honeypot fields. */
		$options     = get_option( 'cf7a_options', array() );
		$input_names = get_honeypot_input_names( $options['honeypot_input_names'] );
		$input_class = sanitize_html_class( $this->options['cf7a_customizations_class'] );
		/**
		 * Controls the maximum number of honeypots.
		 *
		 * @example add_filter( 'cf7a_additional_max_honeypots', function() {return 42});
		 *
		 * @returns int $max_replacements - replacement count number
		 *
		 * @since 0.4.3
		 */
		$max_replacements = intval( apply_filters( 'cf7a_additional_max_honeypots', 5 ) );

		/* get the inputs data */
		if ( $inputs && $inputs->length > 0 ) {
			/* to be on the save side it can be a good idea to store the name of the input (to avoid duplicates) */
			foreach ( $inputs as $i => $input ) {

				if ( $inputs->item( $i )->getAttribute( 'type' ) === 'text' ) {

					$item = $inputs->item( $i );

					$parent  = $item->parentNode;
					$sibling = $item->nextSibling;
					$clone   = $item->cloneNode();

					$item->setAttribute( 'tabindex', '' );
					$item->setAttribute( 'class', $item->getAttribute( 'class' ) );

					$honeypot_names = $input_names[ $i ];

					$clone->setAttribute( 'name', $honeypot_names );
					$clone->setAttribute( 'value', '' );
					$clone->setAttribute( 'autocomplete', 'fill' );
					$clone->setAttribute( 'tabindex', '-1' );
					$clone->setAttribute( 'class', $clone->getAttribute( 'class' ) . ' ' . $input_class . ' autocomplete input' );

					/* duplicate the inputs into honeypots */
					$parent->insertBefore( $clone, $sibling );

					if ( $i > $max_replacements ) {
						return $html->saveHTML();
					}
				}
			}
			return $html->saveHTML();
		}
		return $form_elements;
	}

	/**
	 * It get the content of the page and appends a fake form at the end or at the beginning
	 *
	 * @param string $content The content of the post.
	 */
	public function cf7a_honeyform( $content ) {
		global $post;

		/* $html will store the honeyform html */
		$html = '';

		$form_class = sanitize_html_class( $this->options['cf7a_customizations_class'] );

		$args = array(
			'post_type'      => 'wpcf7_contact_form',
			'posts_per_page' => 1,
		);

		$loop = new WP_Query( $args );
		while ( $loop->have_posts() ) :
			$loop->the_post();

			$wpcf7 = WPCF7_ContactForm::get_template();

			static $global_count = 0;
			++ $global_count;

			$unit_tag = sprintf(
				'wpcf7-f%1$d-p%2$d-o%3$d',
				$wpcf7->id(),
				$post->ID,
				$global_count
			);

			$url  = add_query_arg( array() );
			$frag = strstr( $url, '#' );
			if ( $frag ) {
				$url = substr( $url, 0, -strlen( $frag ) );
			}
			$url .= '#' . $unit_tag;

			$hidden_fields = array(
				'_wpcf7'                  => $wpcf7->id(),
				'_wpcf7_version'          => WPCF7_VERSION,
				'_wpcf7_locale'           => $wpcf7->locale(),
				'_wpcf7_unit_tag'         => $unit_tag,
				'_wpcf7_posted_data_hash' => '',
				'_wpcf7_' . $form_class   => '',
			);

			if ( in_the_loop() ) {
				$hidden_fields['_wpcf7_container_post'] = (int) get_the_ID();
			}

			if ( $wpcf7->nonce_is_active() && is_user_logged_in() ) {
				$hidden_fields['_wpnonce'] = wpcf7_create_nonce();
			}

			$hidden_fields_html = '';

			foreach ( $hidden_fields as $name => $value ) {
				$hidden_fields_html .= sprintf(
					'<input type="hidden" name="%1$s" value="%2$s" />',
					esc_attr( $name ),
					esc_attr( $value )
				) . "\n";
			}

			$atts = array(
				'action'       => esc_url_raw( $url ),
				'method'       => 'post',
				'class'        => 'wpcf7-form init',
				'enctype'      => wpcf7_enctype_value( '' ),
				'autocomplete' => true,
				'novalidate'   => wpcf7_support_html5() ? 'novalidate' : '',
				'data-status'  => 'init',
				'locale'       => $wpcf7->locale(),
			);

			$atts = wpcf7_format_atts( $atts );

			$html .= sprintf(
				'<div %s><div><div class="wpcf7-form"><div class="%s"><div>%s<form %s><div style="display: block;">%s</div>%s%s</form></div></div></div></div></div>',
				wpcf7_format_atts(
					array(
						'role'  => 'form',
						'class' => 'wpcf7',
						'id'    => $unit_tag,
						get_option( 'html_type' ) === 'text/html' ? 'lang' : 'xml:lang'
								=> str_replace( '_', '-', $wpcf7->locale() ),
						'dir'   => wpcf7_is_rtl( $wpcf7->locale() ) ? 'rtl' : 'ltr',
					)
				),
				esc_html( $form_class ),
				$wpcf7->screen_reader_response(),
				$atts,
				$hidden_fields_html,
				$wpcf7->replace_all_form_tags(),
				$wpcf7->form_response_output()
			);

			$html = html_entity_decode( $html, ENT_COMPAT, 'UTF-8' );

		endwhile;

		wp_reset_postdata();

		/* long story, but thinking about the way these bots work the best thing is to have the fake form before the 'real' one */
		return isset( $this->options['honeyform_position'] ) && 'before-content' === sanitize_title( $this->options['honeyform_position'] )
			? sprintf( '%s%s', $html, $content )
			: sprintf( '%s%s', $content, $html );
	}

	/**
	 * It adds a CSS style to the page that hides the honeypot field
	 */
	public function cf7a_add_honeypot_css() {
		$form_class = empty( $this->options['cf7a_customizations_class'] ) ? 'cf7a_' : sanitize_html_class( $this->options['cf7a_customizations_class'] );
		printf( '<style>body div .wpcf7-form .%s{position:absolute;margin-left:-999em;}</style>', esc_attr( $form_class ) );
	}

	/**
	 * It adds hidden fields to the form
	 *
	 * @param array $fields the array of hidden fields that will be added to the form.
	 */
	public function cf7a_add_hidden_fields( $fields ) {

		/* the base hidden field prefix */
		$prefix = sanitize_html_class( $this->options['cf7a_customizations_prefix'] );

		/* add the language if required */
		if ( intval( $this->options['check_language'] ) === 1 ) {
			$accept                          = empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? false : sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
			$fields[ $prefix . '_language' ] = isset( $accept ) ?
				cf7a_crypt( $accept, $this->options['cf7a_cipher'] ) :
				cf7a_crypt( 'language not detected', $this->options['cf7a_cipher'] );
		}

		/* add the timestamp if required */
		if ( intval( $this->options['check_time'] ) === 1 ) {
			$fields[ $prefix . '_timestamp' ] = cf7a_crypt( time(), $this->options['cf7a_cipher'] );
		}

		/* add the default hidden fields */
		$referrer = ! empty( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : false;
		return array_merge(
			$fields,
			array(
				$prefix . 'version' => '1.0',
				$prefix . 'address' => cf7a_crypt( cf7a_get_real_ip(), $this->options['cf7a_cipher'] ),
				$prefix . 'referer' => cf7a_crypt( $referrer ? $referrer : 'no referer', $this->options['cf7a_cipher'] ),
			)
		);
	}

	/**
	 * It adds a hidden field to the form with a unique value that is encrypted with a cipher
	 *
	 * @param array $fields The array of fields that will be sent to the server.
	 *
	 * @return array The array of fields is being returned.
	 */
	public function cf7a_add_bot_fingerprinting( $fields ) {

		$prefix = sanitize_html_class( $this->options['cf7a_customizations_prefix'] );

		return array_merge(
			$fields,
			array(
				$prefix . 'bot_fingerprint' => cf7a_crypt( time(), $this->options['cf7a_cipher'] ),
			)
		);
	}

	/**
	 * It adds a new field to the form, which is a hidden field that will be populated with the bot fingerprinting extras
	 *
	 * @param array $fields The array of fields that are already in the form.
	 *
	 * @return array The $fields array is being merged with the $prefix . 'bot_fingerprint_extras' => false array.
	 */
	public function cf7a_add_bot_fingerprinting_extras( $fields ) {

		$prefix = sanitize_html_class( $this->options['cf7a_customizations_prefix'] );

		return array_merge(
			$fields,
			array(
				$prefix . 'bot_fingerprint_extras' => false,
			)
		);
	}

	/**
	 * It adds a new field to the form, called `cf7a_append_on_submit`, and sets it to false
	 *
	 * @param array $fields The array of fields that are currently being processed.
	 *
	 * @return array The array of fields.
	 */
	public function cf7a_append_on_submit( $fields ) {

		$prefix = sanitize_html_class( $this->options['cf7a_customizations_prefix'] );

		return array_merge(
			$fields,
			array(
				$prefix . 'append_on_submit' => false,
			)
		);
	}

	/**
	 * It disables the XML-RPC protocol and the REST API endpoints for users
	 */
	public function cf7a_protect_user() {
		/* disables the XML-RPC */
		add_filter( 'xmlrpc_enabled', '__return_false' );

		/**
		 * Remove Rest user endpoints
		 *
		 * @return array $endpoints the value of the variable $endpoints.
		 */
		if ( ! is_user_logged_in() ) {
			add_filter(
				'rest_endpoints',
				function( $endpoints ) {
					if ( isset( $endpoints['/wp/v2/users'] ) ) {
						unset( $endpoints['/wp/v2/users'] );
					}
					if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
						unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
					}
					return $endpoints;
				}
			);
		}
	}

	/**
	 * It removes the WordPress version from the header, removes the REST API link from the header, removes the X-Pingback
	 * header, removes the X-Powered-By header, and adds a random server header
	 *
	 * @param array $headers The headers array that is passed to the function.
	 *
	 * @return array The headers are being returned.
	 */
	public function cf7a_protect_wp( $headers ) {

		/* removes version number (WordPress/WooCommerce) */
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'woo_version' );

		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'template_redirect', 'rest_output_link_header', 20 );

		unset( $headers['X-Pingback'] );
		unset( $headers['X-Powered-By'] );

		if ( empty( $headers['X-Frame-Options'] ) ) {
			$headers['X-Frame-Options'] = 'SAMEORIGIN';
		}
		if ( empty( $headers['X-Content-Type-Options'] ) ) {
			$headers['X-Content-Type-Options'] = 'nosniff';
		}
		if ( empty( $headers['X-XSS-Protection'] ) ) {
			$headers['X-XSS-Protection'] = '1; mode=block';
		}
		if ( empty( $headers['Strict-Transport-Security'] ) ) {
			$headers['Strict-Transport-Security'] = 'max-age=31536000';
		}

		return $headers;
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    0.1.0
	 */
	public function enqueue_scripts() {

		/**
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in load_admin as all the hooks are defined
		 * in that particular class.
		 *
		 * The load_admin will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		$asset = include CF7ANTISPAM_PLUGIN_DIR . '/includes/dist/script.asset.php';
		wp_register_script( $this->plugin_name, CF7ANTISPAM_PLUGIN_URL . '/includes/dist/script.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_script( $this->plugin_name );

		wp_localize_script(
			$this->plugin_name,
			'cf7a_settings',
			array(
				'prefix'        => $this->options['cf7a_customizations_prefix'],
				'disableReload' => $this->options['cf7a_disable_reload'],
				'version'       => cf7a_crypt( CF7ANTISPAM_VERSION, $this->options['cf7a_cipher'] ),
			)
		);
	}
}
