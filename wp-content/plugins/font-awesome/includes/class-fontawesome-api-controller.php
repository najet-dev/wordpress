<?php
/**
 * API Controller.
 */
namespace FortAwesome;

require_once trailingslashit( FONTAWESOME_DIR_PATH ) . 'includes/class-fontawesome-metadata-provider.php';
require_once trailingslashit( FONTAWESOME_DIR_PATH ) . 'includes/class-fontawesome-exception.php';
require_once trailingslashit( FONTAWESOME_DIR_PATH ) . 'includes/error-util.php';

use WP_REST_Controller, Error, Exception;

/**
 * Controller class for the plugin's GraphQL API REST endpoint.
 *
 * This controller provides a REST route for WordPress client access to the
 * Font Awesome GraphQL API. It delegates to {@see FontAwesome::query()}.
 * The plugin's setting page is a React app that acts as such a client,
 * querying kits.
 *
 * Requests to this REST route should have the following headers and body:
 *
 * <h3>Headers</h3>
 *
 * - `X-WP-Nonce`: include an appropriate nonce from WordPress.
 * - `Content-Type: application/json`
 *
 * <h3>Body</h3>
 *
 * The request body must contain JSON with a GraphQL query document on the `"query"`
 * property. For example, the following query would return all available Font Awesome
 * version numbers:
 *
 * ```
 * { "query": "query { releases { version } }" }
 * ```
 *
 * It may also contain a "variables" property whose value is an object with variable
 * assignments. For example, the following returns all icon identifiers for the
 * latest version of Font Awesome 6.
 *
 * ```
 * {
 *   "query": "query Icons($ver: String!) { release(version:$ver) { icons { id } } }",
 *   "variables": { "ver": "6.x" }
 * }
 * ```
 *
 * For compatibility with prior versions, this API end point still also allows for
 * sending the request with a plain text body of the query document only, with an
 * implied `content-type: text/plain` header (the default). However, this format is
 * penalized by the OWASP core ruleset used by `mod_security`, so it should not be used.
 *
 * <h3>Internal Use vs. Public API</h3>
 *
 * The PHP methods in this controller class are not part of this plugin's
 * public API, but the REST route it exposes is.
 *
 * If you need to issue a query from server-side PHP code to the
 * Font Awesome API server, use the {@see FontAwesome::query()} method.
 *
 * If you need to issue a query from client-side JavaScript, send
 * an HTTP POST request to WP REST route `/font-awesome/v1/api`.
 */
class FontAwesome_API_Controller extends WP_REST_Controller {

	/**
	 * @ignore
	 * @internal
	 */
	private $plugin_slug = null;

	/**
	 * @ignore
	 * @internal
	 */
	protected $namespace = null;

	/**
	 * @ignore
	 * @internal
	 */
	private $metadata_provider = null;

	/**
	 * @ignore
	 * @internal
	 * @param string $plugin_slug
	 * @param string $rest_namespace
	 */
	public function __construct( $plugin_slug, $rest_namespace ) {
		$this->plugin_slug       = $plugin_slug;
		$this->namespace         = $rest_namespace;
		$this->metadata_provider = fa_metadata_provider();
	}

	/**
	 * Register REST routes.
	 *
	 * @internal
	 * @ignore
	 */
	public function register_routes(): void {
		$route_base = 'api';

		register_rest_route(
			$this->namespace,
			'/' . $route_base,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'query' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $route_base . '/token',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'provide_access_token' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Internal use only. This method is not part of this plugin's public API.
	 *
	 * It's possible that a non-admin user may need to be able
	 * to issue requests through this API Controller, such as
	 * when searching through the Font Awesome API search via
	 * an icon chooser. That's why 'edit_posts' is allowed here.
	 *
	 * However, it seems there are cases where a user may be
	 * able to manage_options but not edit_posts, so we'll include
	 * that permission separately.
	 *
	 * @ignore
	 * @internal
	 * @param WP_REST_Request $request Full data about the request.
	 * @return FontAwesome_REST_Response
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' );
	}

	/**
	 * Run the query by delegating to {@see FontAwesome_Metadata_Provider}.
	 *
	 * Internal use only. This method is not part of this plugin's public API.
	 *
	 * @ignore
	 * @internal
	 * @param WP_REST_Request $request Full data about the request.
	 * @return FontAwesome_REST_Response
	 */
	public function query( $request ): FontAwesome_REST_Response {
		try {
			$query_body = $this->get_query_body( $request );

			$result = $this->metadata_provider()->metadata_query( $query_body );

			return new FontAwesome_REST_Response( json_decode( $result, true ), 200 );
		} catch ( FontAwesome_ServerException $e ) {
			return new FontAwesome_REST_Response( wpe_fontawesome_server_exception( $e ), 500 );
		} catch ( FontAwesome_Exception $e ) {
			return new FontAwesome_REST_Response( wpe_fontawesome_client_exception( $e ), 400 );
		} catch ( Exception $e ) {
			return new FontAwesome_REST_Response( wpe_fontawesome_unknown_error( $e ), 500 );
		} catch ( Error $e ) {
			return new FontAwesome_REST_Response( wpe_fontawesome_unknown_error( $e ), 500 );
		}
	}

	/**
	 * Respond with the access token, refreshing it first if need be.
	 *
	 * Internal use only. This method is not part of this plugin's public API.
	 *
	 * @ignore
	 * @internal
	 * @return FontAwesome_REST_Response
	 */
	// phpcs:ignore Squiz.Commenting.FunctionCommentThrowTag.Missing
	public function provide_access_token(): FontAwesome_REST_Response {
		try {
			$access_token = fa_api_settings()->current_access_token();
			$expires_at   = fa_api_settings()->access_token_expiration_time();

			if ( ! boolval( $access_token ) || ! boolval( $expires_at ) ) {
				throw new NoAccessTokenException();
			}

			$response_body = array(
				'access_token' => $access_token,
				'expires_at'   => $expires_at,
			);

			return new FontAwesome_REST_Response( $response_body, 200 );
		} catch ( FontAwesome_ServerException $e ) {
			return new FontAwesome_REST_Response( wpe_fontawesome_server_exception( $e ), 500 );
		} catch ( FontAwesome_Exception $e ) {
			return new FontAwesome_REST_Response( wpe_fontawesome_client_exception( $e ), 400 );
		} catch ( Exception $e ) {
			return new FontAwesome_REST_Response( wpe_fontawesome_unknown_error( $e ), 500 );
		} catch ( Error $e ) {
			return new FontAwesome_REST_Response( wpe_fontawesome_unknown_error( $e ), 500 );
		}
	}

	/**
	 * Allows a test subclass to mock the metadata provider.
	 *
	 * Internal use only, not part of this plugin's public API.
	 *
	 * @internal
	 * @ignore
	 */
	protected function metadata_provider(): FontAwesome_Metadata_Provider {
		return $this->metadata_provider;
	}

	/**
	 * @param mixed $request
	 * @return string|array
	 */
	private function get_query_body( $request ) {
		if ( $request->get_header( 'Content-Type' ) === 'application/json' ) {
			return $request->get_json_params();
		} else {
			return $request->get_body();
		}
	}
}
