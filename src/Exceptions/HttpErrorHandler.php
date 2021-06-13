<?php

namespace Brid\Http\Exceptions;

use Throwable;
use Slim\Handlers\ErrorHandler;
use Slim\Exception\HttpException;
use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Exception\HttpNotImplementedException;
use Slim\Exception\HttpMethodNotAllowedException;

class HttpErrorHandler extends ErrorHandler
{
  public const BAD_REQUEST = 'BAD_REQUEST';
  public const INSUFFICIENT_PRIVILEGES = 'INSUFFICIENT_PRIVILEGES';
  public const NOT_ALLOWED = 'NOT_ALLOWED';
  public const NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';
  public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
  public const SERVER_ERROR = 'SERVER_ERROR';
  public const UNAUTHENTICATED = 'UNAUTHENTICATED';

  protected function respond(): ResponseInterface
  {
    $exception = $this->exception;
    $statusCode = method_exists($exception, 'getHttpStatusCode') ? $exception->getHttpStatusCode() : 500;
    $type = method_exists($exception, 'getErrorType') ? $exception->getErrorType() : self::SERVER_ERROR;
    $description = 'An internal error has occurred while processing your request.';

    if ($exception instanceof HttpException) {
      $statusCode = $exception->getCode();
      $description = $exception->getMessage();

      if ($exception instanceof HttpNotFoundException) {
        $type = self::RESOURCE_NOT_FOUND;
      } elseif ($exception instanceof HttpMethodNotAllowedException) {
        $type = self::NOT_ALLOWED;
      } elseif ($exception instanceof HttpUnauthorizedException) {
        $type = self::UNAUTHENTICATED;
      } elseif ($exception instanceof HttpForbiddenException) {
        $type = self::UNAUTHENTICATED;
      } elseif ($exception instanceof HttpBadRequestException) {
        $type = self::BAD_REQUEST;
      } elseif ($exception instanceof HttpNotImplementedException) {
        $type = self::NOT_IMPLEMENTED;
      }
    }

    if (method_exists($exception, 'getHint')) {
      $title = $exception->getHint();
    } else if (method_exists($exception, 'getTitle')) {
      $title = $exception->getTitle();
    } else {
      $title = strtoupper($type);
    }

    if (
      !($exception instanceof HttpException)
      && ($exception instanceof Throwable)
      && $this->displayErrorDetails
    ) {
      $description = $exception->getMessage();
    }

    $body = [
      'errors' => [
        [
          'status' => $statusCode,
          'title' => $title,
          'detail' => $description,
        ]
      ],
    ];

    if ($this->request->wantsJson()) {
      $contentType = 'application/vnd.api+json';
      $payload = json_encode($body, JSON_PRETTY_PRINT);
    } else {
      $contentType = 'text/html';
      $payload = '<html><head><title>' . $body['errors'][0]['status'] . ' Error</title></head><body><h1>' .
        $body['errors'][0]['title'] . '</h1><br><pre>' . $body['errors'][0]['detail'] . '</pre></body></html>';
    }

    $response = $this->responseFactory
      ->createResponse($statusCode)
      ->withHeader('Content-Type', $contentType);

    $response
      ->getBody()
      ->write($payload);

    return $response;
  }
}
