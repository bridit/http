<?php

namespace Brid\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Class IpAddress
 * @package Brid\Http
 * @see https://github.com/akrabat/rka-ip-address-middleware
 */
class IpAddress
{

  /**
   * Enable checking of proxy headers (X-Forwarded-For to determined client IP.
   *
   * Defaults to false as only $_SERVER['REMOTE_ADDR'] is a trustworthy source
   * of IP address.
   *
   * @var bool
   */
  protected bool $checkProxyHeaders;

  /**
   * List of trusted proxy IP addresses
   *
   * If not empty, then one of these IP addresses must be in $_SERVER['REMOTE_ADDR']
   * in order for the proxy headers to be looked at.
   *
   * @var array
   */
  protected array $trustedProxies;

  /**
   * Name of the attribute added to the ServerRequest object
   *
   * @var string
   */
  protected $attributeName = 'ip_address';

  /**
   * List of proxy headers inspected for the client IP address
   *
   * @var array
   */
  protected $headersToInspect = [
    'Forwarded',
    'X-Forwarded',
    'X-Forwarded-For',
    'HTTP_X_FORWARDED_FOR',
    'X-Cluster-Client-Ip',
    'Client-Ip',
  ];

  /**
   * Constructor
   *
   * @param bool $checkProxyHeaders Whether to use proxy headers to determine client IP
   * @param array $trustedProxies   List of IP addresses of trusted proxies
   * @param string $attributeName   Name of attribute added to ServerRequest object
   * @param array $headersToInspect List of headers to inspect
   */
  public function __construct(
    $checkProxyHeaders = false,
    array $trustedProxies = [],
    $attributeName = null,
    array $headersToInspect = []
  ) {
    $this->checkProxyHeaders = $checkProxyHeaders;
    $this->trustedProxies = $trustedProxies;

    if ($attributeName) {
      $this->attributeName = $attributeName;
    }
    if (!empty($headersToInspect)) {
      $this->headersToInspect = $headersToInspect;
    }
  }

  /**
   * {@inheritDoc}
   *
   * Set the "$attributeName" attribute to the client's IP address as determined from
   * the proxy header (X-Forwarded-For or from $_SERVER['REMOTE_ADDR']
   */
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $ipAddress = $this->determineClientIpAddress($request);
    $request = $request->withAttribute($this->attributeName, $ipAddress);

    return $handler->handle($request);
  }

  /**
   * Set the "$attributeName" attribute to the client's IP address as determined from
   * the proxy header (X-Forwarded-For or from $_SERVER['REMOTE_ADDR']
   *
   * @param ServerRequestInterface $request PSR7 request
   * @param ResponseInterface $response     PSR7 response
   * @param callable $next                  Next middleware
   *
   * @return ResponseInterface
   */
  public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
  {
    if (!is_callable($next)) {
      return $response;
    }

    $ipAddress = $this->determineClientIpAddress($request);
    $request = $request->withAttribute($this->attributeName, $ipAddress);

    return $response = $next($request, $response);
  }

  /**
   * Find out the client's IP address from the headers available to us
   *
   * @param  ServerRequestInterface $request PSR-7 Request
   * @return string
   */
  public function determineClientIpAddress($request)
  {
    $ipAddress = null;

    $serverParams = $request->getServerParams();
    if (isset($serverParams['REMOTE_ADDR']) && $this->isValidIpAddress($serverParams['REMOTE_ADDR'])) {
      $ipAddress = $serverParams['REMOTE_ADDR'];
    }

    if ($this->checkProxyHeaders
      && !empty($this->trustedProxies)
      && in_array($ipAddress, $this->trustedProxies)
    ) {
      foreach ($this->headersToInspect as $header) {
        if ($request->hasHeader($header)) {
          $ip = $this->getFirstIpAddressFromHeader($request, $header);
          if ($this->isValidIpAddress($ip)) {
            $ipAddress = $ip;
            break;
          }
        }
      }
    }

    return $ipAddress;
  }

  /**
   * Check that a given string is a valid IP address
   *
   * @param  string  $ip
   * @return boolean
   */
  protected function isValidIpAddress($ip)
  {
    $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
    if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
      return false;
    }
    return true;
  }

  /**
   * Find out the client's IP address from the headers available to us
   *
   * @param  ServerRequestInterface $request PSR-7 Request
   * @param  string $header Header name
   * @return string
   */
  private function getFirstIpAddressFromHeader($request, $header)
  {
    $items = explode(',', $request->header($header));
    $headerValue = trim(reset($items));

    if (ucfirst($header) == 'Forwarded') {
      foreach (explode(';', $headerValue) as $headerPart) {
        if (strtolower(substr($headerPart, 0, 4)) == 'for=') {
          $for = explode(']', $headerPart);
          $headerValue = trim(substr(reset($for), 4), " \t\n\r\0\x0B" . "\"[]");
          break;
        }
      }
    }

    return $headerValue;
  }

}