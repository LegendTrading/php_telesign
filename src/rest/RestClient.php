<?php

namespace telesign\sdk\rest;

use Eloquent\Composer\Configuration\ConfigurationReader;
use GuzzleHttp\Client;
use Ramsey\Uuid\Uuid;

use const telesign\sdk\VERSION;

/**
 * The TeleSign RestClient is a generic HTTP REST client that can be extended to make requests against any of
 * TeleSign's REST API endpoints.
 *
 * RequestEncodingMixin offers the function _encode_params for url encoding the body for use in string_to_sign outside
 * of a regular HTTP request.
 *
 * See https://developer.telesign.com for detailed API documentation.
 */
class RestClient {

  private $customer_id;
  private $secret_key;
  private $user_agent;
  private $client;

  /**
   * TeleSign RestClient instantiation function
   *
   * @param string   $customer_id Your customer_id string associated with your account
   * @param string   $secret_key  Your secret_key string associated with your account
   * @param string   $api_host    Override the default api_host to target another endpoint string
   * @param float    $timeout     How long to wait for the server to send data before giving up
   * @param stirng   $proxy       URL of the proxy
   * @param callable $handler     Guzzle's HTTP transfer override
   */
  function __construct (
    $customer_id,
    $secret_key,
    $api_host = "https://rest.telesign.com",
    $timeout = 10,
    $proxy = null,
    $handler = null
  ) {
    $this->customer_id = $customer_id;
    $this->secret_key = $secret_key;

    $this->client = new Client([
      "base_uri" => $api_host,
      "timeout" => $timeout,
      "proxy" => $proxy,
      "handler" => $handler
    ]);

    $sdk_version = VERSION;
    $php_version = PHP_VERSION;
    $guzzle_version = Client::VERSION;

    $this->user_agent = "TeleSignSDK/php-$sdk_version PHP/$php_version Guzzle/$guzzle_version";
  }

  /**
   * Generates the TeleSign REST API headers used to authenticate requests.
   *
   * Creates the canonicalized string_to_sign and generates the HMAC signature. This is used to authenticate requests
   * against the TeleSign REST API.
   *
   * See https://developer.telesign.com/docs/authentication-1 for detailed API documentation.
   *
   * @param string $customer_id        Your account customer_id
   * @param string $secret_key         Your account secret_key
   * @param string $method_name        The HTTP method name of the request, should be one of 'POST', 'GET', 'PUT' or
   *                                   'DELETE'
   * @param string $resource           The partial resource URI to perform the request against
   * @param string $url_encoded_fields HTTP body parameters to perform the HTTP request with, must be urlencoded
   * @param string $date_rfc2616       The date and time of the request formatted in rfc 2616
   * @param string $nonce              A unique cryptographic nonce for the request
   * @param string $user_agent         User Agent associated with the request
   *
   * @return array The TeleSign authentication headers
   */
  static function generateTelesignHeaders (
    $customer_id,
    $secret_key,
    $method_name,
    $resource,
    $url_encoded_fields,
    $date_rfc2616 = null,
    $nonce = null,
    $user_agent = null
  ) {
    if (!$date_rfc2616) {
      $date_rfc2616 = gmdate("D, d M Y H:i:s T");
    }

    if (!$nonce) {
      $nonce = Uuid::uuid4()->toString();
    }

    $content_type = in_array($method_name, ["POST", "PUT"]) ? "application/x-www-form-urlencoded" : "";

    $auth_method = "HMAC-SHA256";

    $string_to_sign_builder = [
      $method_name,
      "\n$content_type",
      "\n$date_rfc2616",
      "\nx-ts-auth-method:$auth_method",
      "\nx-ts-nonce:$nonce"
    ];

    if ($content_type) {
      $string_to_sign_builder[] = "\n$url_encoded_fields";
    }

    $string_to_sign_builder[] = "\n$resource";

    $string_to_sign = join("", $string_to_sign_builder);

    $signature = base64_encode(
      hash_hmac("sha256", utf8_encode($string_to_sign), base64_decode($secret_key), true)
    );
    $authorization = "TSA $customer_id:$signature";

    $headers = [
      "Authorization" => $authorization,
      "Date" => $date_rfc2616,
      "Content-Type" => $content_type,
      "x-ts-auth-method" => $auth_method,
      "x-ts-nonce" => $nonce
    ];

    if ($user_agent) {
      $headers["User-Agent"] = $user_agent;
    }

    return $headers;
  }

  /**
   * Generic TeleSign REST API POST handler
   *
   * @param string $resource     The partial resource URI to perform the request against
   * @param array  $fields       Body params to perform the POST request with
   * @param string $date_rfc2616 The date and time of the request formatted in rfc 2616
   * @param string $nonce        A unique cryptographic nonce for the request
   *
   * @return \telesign\sdk\rest\Response The RestClient Response object
   */
  function post (...$args) {
    return $this->execute("POST", ...$args);
  }

  /**
   * Generic TeleSign REST API GET handler
   *
   * @param string $resource     The partial resource URI to perform the request against
   * @param array  $fields       Query params to perform the GET request with
   * @param string $date_rfc2616 The date and time of the request formatted in rfc 2616
   * @param string $nonce        A unique cryptographic nonce for the request
   *
   * @return \telesign\sdk\rest\Response The RestClient Response object
   */
  function get (...$args) {
    return $this->execute("GET", ...$args);
  }

  /**
   * Generic TeleSign REST API DELETE handler
   *
   * @param string $resource     The partial resource URI to perform the request against
   * @param array  $fields       Query params to perform the DELETE request with
   * @param string $date_rfc2616 The date and time of the request formatted in rfc 2616
   * @param string $nonce        A unique cryptographic nonce for the request
   *
   * @return \telesign\sdk\rest\Response The RestClient Response object
   */
  function delete (...$args) {
    return $this->execute("DELETE", ...$args);
  }

  /**
   * Generic TeleSign REST API request handler
   *
   * @param string $resource     The partial resource URI to perform the request against
   * @param array  $fields       Body of query params to perform the HTTP request with
   * @param string $date_rfc2616 The date and time of the request formatted in rfc 2616
   * @param string $nonce        A unique cryptographic nonce for the request
   *
   * @return \telesign\sdk\rest\Response The RestClient Response object
   */
  private function execute ($method_name, $resource, $fields = [], $date_rfc2616 = null, $nonce = null) {
    $url_encoded_fields = http_build_query($fields, null, "&");

    $headers = $this->generateTelesignHeaders(
      $this->customer_id,
      $this->secret_key,
      $method_name,
      $resource,
      $url_encoded_fields,
      $date_rfc2616,
      $nonce,
      $this->user_agent
    );

    $option = in_array($method_name, [ "POST", "PUT" ]) ? "body" : "query";

    return new Response($this->client->request($method_name, $resource, [
      "headers" => $headers,
      $option => $url_encoded_fields
    ]));
  }

}