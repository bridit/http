<?php

namespace Brid\Http;

use Closure;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use GuzzleHttp\Psr7\UploadedFile;
use Psr\Http\Message\UriInterface;
use GuzzleHttp\Psr7\CachingStream;
use GuzzleHttp\Psr7\LazyOpenStream;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\ServerRequestInterface;
use GuzzleHttp\Psr7\Request as GuzzleHttpRequest;
use Brid\Http\Concerns\InteractsWithHeaders;
use Brid\Http\Concerns\InteractsWithContentTypes;
use Slim\Routing\Route;
use Slim\Routing\RouteContext;

/**
 * Server-side HTTP request
 *
 * Extends the Request definition to add methods for accessing incoming data,
 * specifically server parameters, cookies, matched path parameters, query
 * string arguments, body parameters, and upload file information.
 *
 * "Attributes" are discovered via decomposing the request (and usually
 * specifically the URI path), and typically will be injected by the application.
 *
 * Requests are considered immutable; all methods that might change state are
 * implemented such that they retain the internal state of the current
 * message and return a new instance that contains the changed state.
 */
class Request extends GuzzleHttpRequest implements ServerRequestInterface
{

  use InteractsWithHeaders, InteractsWithContentTypes, Macroable;

  /**
   * @var array
   */
  private array $attributes = [];

  /**
   * @var array
   */
  private array $cookieParams = [];

  /**
   * @var array|object|null
   */
  private $parsedBody;

  /**
   * @var array
   */
  private array $queryParams = [];

  /**
   * @var array
   */
  private array $serverParams;

  /**
   * @var array
   */
  private array $uploadedFiles = [];

  /**
   * @var string|null
   */
  private ?string $clientIp = null;

  /**
   * @var array|null
   */
  protected ?array $languages = null;

  /**
   * @var array|null
   */
  protected ?array $charsets = null;

  /**
   * @var array|null
   */
  protected ?array $encodings = null;

  /**
   * @var array|null
   */
  protected ?array $acceptableContentTypes = null;

  /**
   * @var string|null
   */
  protected ?string $preferredFormat = null;

  /**
   * The user resolver callback.
   *
   * @var Closure|null
   */
  protected ?Closure $userResolver = null;

  /**
   * The route resolver callback.
   *
   * @var Closure|null
   */
  protected ?Closure $routeResolver = null;

  /**
   * @param string                               $method       HTTP method
   * @param string|UriInterface                  $uri          URI
   * @param array                                $headers      Request headers
   * @param string|resource|StreamInterface|null $body         Request body
   * @param string                               $version      Protocol version
   * @param array                                $serverParams Typically the $_SERVER superglobal
   */
  public function __construct(
    $method,
    $uri,
    array $headers = [],
    $body = null,
    $version = '1.1',
    array $serverParams = []
  ) {
    $this->serverParams = $serverParams;

    parent::__construct($method, $uri, array_change_key_case($headers,CASE_LOWER), $body, $version);
  }

  /**
   * Return an UploadedFile instance array.
   *
   * @param array $files A array which respect $_FILES structure
   *
   * @return array
   *
   * @throws InvalidArgumentException for unrecognized values
   */
  public static function normalizeFiles(array $files): array
  {
    $normalized = [];

    foreach ($files as $key => $value) {
      if ($value instanceof UploadedFileInterface) {
        $normalized[$key] = $value;
      } elseif (is_array($value) && isset($value['tmp_name'])) {
        $normalized[$key] = self::createUploadedFileFromSpec($value);
      } elseif (is_array($value)) {
        $normalized[$key] = self::normalizeFiles($value);
        continue;
      } else {
        throw new InvalidArgumentException('Invalid value in files specification');
      }
    }

    return $normalized;
  }

  /**
   * Create and return an UploadedFile instance from a $_FILES specification.
   *
   * If the specification represents an array of values, this method will
   * delegate to normalizeNestedFileSpec() and return that return value.
   *
   * @param array $value $_FILES struct
   *
   * @return array|UploadedFileInterface
   */
  private static function createUploadedFileFromSpec(array $value)
  {
    if (is_array($value['tmp_name'])) {
      return self::normalizeNestedFileSpec($value);
    }

    return new UploadedFile(
      $value['tmp_name'],
      (int) $value['size'],
      (int) $value['error'],
      $value['name'],
      $value['type']
    );
  }

  /**
   * Normalize an array of file specifications.
   *
   * Loops through all nested files and returns a normalized array of
   * UploadedFileInterface instances.
   *
   * @param array $files
   *
   * @return UploadedFileInterface[]
   */
  private static function normalizeNestedFileSpec(array $files = []): array
  {
    $normalizedFiles = [];

    foreach (array_keys($files['tmp_name']) as $key) {
      $spec = [
        'tmp_name' => $files['tmp_name'][$key],
        'size'     => $files['size'][$key],
        'error'    => $files['error'][$key],
        'name'     => $files['name'][$key],
        'type'     => $files['type'][$key],
      ];
      $normalizedFiles[$key] = self::createUploadedFileFromSpec($spec);
    }

    return $normalizedFiles;
  }

  /**
   * Return a ServerRequest populated with superglobals:
   * $_GET
   * $_POST
   * $_COOKIE
   * $_FILES
   * $_SERVER
   *
   * @return ServerRequestInterface
   */
  public static function fromGlobals()
  {
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    $headers = getallheaders();
    $uri = self::getUriFromGlobals();
    $body = new CachingStream(new LazyOpenStream('php://input', 'r+'));
    $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? str_replace('HTTP/', '', $_SERVER['SERVER_PROTOCOL']) : '1.1';

    $serverRequest = new self($method, $uri, $headers, $body, $protocol, $_SERVER);

    return $serverRequest
      ->withCookieParams($_COOKIE)
      ->withQueryParams($_GET)
      ->withParsedBody($_POST)
      ->withUploadedFiles(self::normalizeFiles($_FILES));
  }

  private static function extractHostAndPortFromAuthority($authority)
  {
    $uri = 'http://' . $authority;
    $parts = parse_url($uri);
    if (false === $parts) {
      return [null, null];
    }

    $host = isset($parts['host']) ? $parts['host'] : null;
    $port = isset($parts['port']) ? $parts['port'] : null;

    return [$host, $port];
  }

  /**
   * Get a Uri populated with values from $_SERVER.
   *
   * @return UriInterface
   */
  public static function getUriFromGlobals()
  {
    $uri = new Uri('');

    $uri = $uri->withScheme(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');

    $hasPort = false;
    if (isset($_SERVER['HTTP_HOST'])) {
      list($host, $port) = self::extractHostAndPortFromAuthority($_SERVER['HTTP_HOST']);
      if ($host !== null) {
        $uri = $uri->withHost($host);
      }

      if ($port !== null) {
        $hasPort = true;
        $uri = $uri->withPort($port);
      }
    } elseif (isset($_SERVER['SERVER_NAME'])) {
      $uri = $uri->withHost($_SERVER['SERVER_NAME']);
    } elseif (isset($_SERVER['SERVER_ADDR'])) {
      $uri = $uri->withHost($_SERVER['SERVER_ADDR']);
    }

    if (!$hasPort && isset($_SERVER['SERVER_PORT'])) {
      $uri = $uri->withPort($_SERVER['SERVER_PORT']);
    }

    $hasQuery = false;
    if (isset($_SERVER['REQUEST_URI'])) {
      $requestUriParts = explode('?', $_SERVER['REQUEST_URI'], 2);
      $uri = $uri->withPath($requestUriParts[0]);
      if (isset($requestUriParts[1])) {
        $hasQuery = true;
        $uri = $uri->withQuery($requestUriParts[1]);
      }
    }

    if (!$hasQuery && isset($_SERVER['QUERY_STRING'])) {
      $uri = $uri->withQuery($_SERVER['QUERY_STRING']);
    }

    return $uri;
  }

  /**
   * {@inheritdoc}
   */
  public function getServerParams()
  {
    return $this->serverParams;
  }

  /**
   * {@inheritdoc}
   */
  public function getUploadedFiles()
  {
    return $this->uploadedFiles;
  }

  /**
   * {@inheritdoc}
   */
  public function withUploadedFiles(array $uploadedFiles)
  {
    $new = clone $this;
    $new->uploadedFiles = $uploadedFiles;

    return $new;
  }

  /**
   * {@inheritdoc}
   */
  public function getCookieParams()
  {
    return $this->cookieParams;
  }

  /**
   * {@inheritdoc}
   */
  public function withCookieParams(array $cookies)
  {
    $new = clone $this;
    $new->cookieParams = $cookies;

    return $new;
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryParams()
  {
    return $this->queryParams;
  }

  /**
   * {@inheritdoc}
   */
  public function withQueryParams(array $query)
  {
    $new = clone $this;
    $new->queryParams = $query;

    return $new;
  }

  /**
   * {@inheritdoc}
   */
  public function getParsedBody()
  {
    return $this->parsedBody;
  }

  /**
   * {@inheritdoc}
   */
  public function withParsedBody($data)
  {
    $new = clone $this;
    $new->parsedBody = $data;

    return $new;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttributes()
  {
    return $this->attributes;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttribute($attribute, $default = null)
  {
    if (false === array_key_exists($attribute, $this->attributes)) {
      return $default;
    }

    return $this->attributes[$attribute];
  }

  /**
   * {@inheritdoc}
   */
  public function withAttribute($attribute, $value)
  {
    $new = clone $this;
    $new->attributes[$attribute] = $value;

    return $new;
  }

  /**
   * {@inheritdoc}
   */
  public function withoutAttribute($attribute)
  {
    if (false === array_key_exists($attribute, $this->attributes)) {
      return $this;
    }

    $new = clone $this;
    unset($new->attributes[$attribute]);

    return $new;
  }

  /**
   * Determine if the request is the result of an AJAX call.
   *
   * @return bool
   */
  public function ajax()
  {
    return $this->isXmlHttpRequest();
  }

  /**
   * Determine if the request is the result of a PJAX call.
   *
   * @return bool
   */
  public function pjax()
  {
    return $this->header('X-PJAX') == true;
  }

  /**
   * @param string $key
   * @param mixed $default
   * @return mixed
   */
  public function header(string $key, mixed $default = null): mixed
  {
    $value = $this->getHeaderLine($key);

    return !blank($value) ? $value : $default;
  }

  /**
   * @param string $key
   * @param mixed $default
   * @return mixed
   */
  public function server(string $key, mixed $default = null): mixed
  {
    return Arr::get($this->getServerParams(), $key, $default);
  }

  /**
   * @return array
   */
  public function all(): array
  {
    return array_merge($this->queryParams ?? [], $this->parsedBody ?? []);
  }

  /**
   * @param string|array $keys
   * @return bool
   */
  public function has(string|array $keys): bool
  {
    return Arr::has($this->all(), $keys);
  }

  /**
   * @param string $key
   * @param mixed $default
   * @return mixed
   */
  public function get(string $key, mixed $default = null): mixed
  {
    return Arr::get($this->all(), $key, $default);
  }

  /**
   * @param array|string $keys
   * @return array
   */
  public function only(array|string $keys): array
  {
    return Arr::only($this->all(), $keys);
  }

  public function getClientIp(): string
  {
    if (null !== $this->clientIp) {
      return $this->clientIp;
    }

    $this->clientIp = (new IpAddress(true, ['127.0.0.1']))->determineClientIpAddress($this);

    return $this->clientIp;
  }

  public function getClientUserAgent(): string
  {
    return $this->getServerParams()['HTTP_USER_AGENT'] ?? '';
  }

  /**
   * Get the user making the request.
   *
   * @param string|null $guard
   * @return mixed
   */
  public function user(string $guard = null): mixed
  {
    return call_user_func_array($this->getUserResolver(), [$this, $guard]);
  }

  /**
   * Get the user resolver callback.
   *
   * @return Closure
   */
  public function getUserResolver(): Closure
  {
    return $this->userResolver ?? function () {
        //
      };
  }

  /**
   * Set the user resolver callback.
   *
   * @param  Closure  $callback
   * @return $this
   */
  public function setUserResolver(Closure $callback): static
  {
    $this->userResolver = $callback;

    return $this;
  }

  /**
   * Get the route handling the request.
   *
   * @param string|null $param
   * @param mixed|null $default
   * @return Route|string|null
   */
  public function route(string $param = null, mixed $default = null): Route|string|null
  {
    $route = call_user_func($this->getRouteResolver());

    if (is_null($route) || is_null($param)) {
      return $route;
    }

    return $route->getArgument($param, $default);
  }

  /**
   * Get the route resolver callback.
   *
   * @return Closure
   */
  public function getRouteResolver(): Closure
  {
    return $this->routeResolver ?? function () {
        return RouteContext::fromRequest($this)->getRoute();
      };
  }

  /**
   * Set the route resolver callback.
   *
   * @param  Closure  $callback
   * @return $this
   */
  public function setRouteResolver(Closure $callback): static
  {
    $this->routeResolver = $callback;

    return $this;
  }

}
