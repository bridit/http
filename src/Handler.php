<?php declare(strict_types=1);

namespace Brid\Http;

use Brid\Core\Foundation\Log;
use Slim\App;
use Exception;
use DI\Bridge\Slim\Bridge as SlimBridge;
use Brid\Http\Exceptions\ShutdownHandler;
use Brid\Http\Exceptions\HttpErrorHandler;
use Brid\Http\Middleware\BodyParsingMiddleware;

class Handler extends \Brid\Core\Handlers\Handler
{

  protected array $middleware = [];

  /**
   * @inheritDoc
   */
  protected function boot(string $basePath = null): static
  {
    define('APP_HANDLER_TYPE', 'http');

    return parent::boot($basePath);
  }

  public function withMiddleware(array $middleware): static
  {
    $this->middleware = $middleware;

    return $this;
  }

  /**
   * @throws Exception
   */
  protected function getSlimInstance(): App
  {

    $app = SlimBridge::create(app());

    $this->bootRequest();
    $this->bootMiddleware($app);
    $this->bootRouter($app);

    return $app;

  }

  protected function bootMiddleware(App &$app): void
  {

    $this->bootErrorHandler($app);

    foreach ($this->middleware as $middleware)
    {
      $app->addMiddleware($middleware);
    }

  }

  protected function bootErrorHandler(App &$app): void
  {

    $displayErrorDetails = env('APP_ENV') === 'local';
    $callableResolver = $app->getCallableResolver();
    $responseFactory = $app->getResponseFactory();

    $request = $this->get('request');

    $errorHandler = new HttpErrorHandler($callableResolver, $responseFactory, Log::getLogger());
    $shutdownHandler = new ShutdownHandler($request, $errorHandler, $displayErrorDetails);
    register_shutdown_function($shutdownHandler);

    // Add Routing Middleware
    $app->addRoutingMiddleware();

    // Add Error Handling Middleware
    $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
    $errorMiddleware->setDefaultErrorHandler($errorHandler);

  }

  protected function bootRouter(App &$app): void
  {

    $app->getRouteCollector()
      ->setDefaultInvocationStrategy(new \Slim\Handlers\Strategies\RequestResponseArgs())
//      ->setCacheFile(__DIR__ . '/../bootstrap/cache/http-routes.cache')
    ;

    require path('/routes/http.php');

  }

  protected function bootRequest(): void
  {
    $this->set('request', (new BodyParsingMiddleware())->execute(Request::fromGlobals()));
  }

  public function handle($event = null, $context = null)
  {

    $app = $this->getSlimInstance();
    
    parent::handle($event, $context);

    $app->run($this->get('request'));

  }

}
