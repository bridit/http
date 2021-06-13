<?php

namespace Brid\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;

class BodyParsingMiddleware extends \Slim\Middleware\BodyParsingMiddleware
{

  public function execute(ServerRequestInterface $request): ServerRequestInterface
  {

    $parsedBody = $request->getParsedBody();

    if ($parsedBody === null || empty($parsedBody)) {
      $parsedBody = $this->parseBody($request);
      $request = $request->withParsedBody($parsedBody);
    }

    return $request;

  }
  
}