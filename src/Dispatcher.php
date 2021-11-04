<?php

namespace AmraniCh\AjaxDispatcher;

/**
 * AmraniCh\AjaxDispatcher\Dispatcher
 *
 * Handle AJAX requests and send them to an appropriate handler.
 *
 * @since 1.0
 * @author El Amrani Chakir <contact@amranich.dev>
 * @link https://amranich.dev
 */
class Dispatcher
{
    /** @var array */
    protected $server;

    /** @var array */
    protected $handlers;

    /** @var array */
    protected $context;

    /** @var callable */
    protected $beforeCallback;

    /** @var callable */
    protected $onExceptionCallback;

    /** @var array */
    protected $controllers = [];

    /** @var array */
    protected $HTTPMethods = [
        'GET',
        'POST',
        'PUT',
        'DELETE',
        'PATCH'
    ];

    /** @var string */
    protected $handlerName;

    /** @var string */
    protected $requestMethod;

    /**
     * Dispatcher Constructor.
     *
     * @param array  $server      The server variables.
     * @param string $handlerName The handler name to be executed.
     * @param array  $handlers    An associative array that register request handlers.
     */
    public function __construct($server, $handlerName, $handlers)
    {
        $this->server      = $server;
        $this->handlerName = $handlerName;
        $this->handlers    = $handlers;
    }

    /**
     * Start dispatching the current AJAX request to the appropriate handler 
     * (controller method or a callback function).
     *
     * @return void
     * @throws DispatcherException
     */
    public function dispatch()
    {
        $this->checkScriptContext();

        $requestMethod = $this->server['REQUEST_METHOD'];

        $this->throwExceptionIfNotValidRequestMethod($requestMethod);

        $this->validateHandlers($this->handlers);

        $this->requestMethod = $requestMethod;
        $this->context       = $this->getRequestVariables($requestMethod);

        if (is_callable($this->beforeCallback)) {
            if ($this->handleException(function () {
                return call_user_func($this->beforeCallback, (object) $this->context);
            }) === false) {
                return;
            }
        }

        if (!array_key_exists($this->handlerName, $this->context)) {
            throw new DispatcherException("the key '$this->handlerName' not found in request variables.");
        }

        $this->handle();
    }

    /**
     * Register controllers instances and namespaces.
     *
     * @param array $controllers An array of controller instances or namespaces.
     *
     * @return Dispatcher
     */
    public function registerControllers($controllers)
    {
        foreach ($controllers as $controller) {
            if (is_string($controller)) {
                $this->controllers[] = new $controller();
                continue;
            }
            $this->controllers[] = $controller;
        }

        return $this;
    }

    /**
     * Executes some code before dispatching the current request.
     *
     * @param callable $callback The callback function to be executed, all the request parameters will be passed to
     *                           this callback as a first argument.
     *
     * @return Dispatcher
     */
    public function before($callback)
    {
        $this->beforeCallback = $callback;
        return $this;
    }

    /**
     * Allows to use a custom exception handler for exceptions that may be thrown when calling
     * a handler for the current AJAX request.
     *
     * @param callable $callback a callback function that will accept the exception as a first argument.
     *
     * @return Dispatcher
     */
    public function onException($callback)
    {
        $this->onExceptionCallback = $callback;
        return $this;
    }

    /**
     * Checks if the current script was executed via an HTTP client like a browser,
     * if so check the HTTP request is issued using the XMLHTTPRequest in the client side.
     *
     * @return void
     * @throws DispatcherException
     */
    protected function checkScriptContext()
    {
        if (!function_exists('getallheaders')) {
            throw new DispatcherException('AjaxDispatcher works only within an HTTP request context '
                . '(request that issued by a HTTP client like a browser).');
        }

        $headers = getallheaders();

        if ($headers === false) {
            throw new DispatcherException(sprintf(
                'An error occur when trying retrieving the current HTTP request headers : %s',
                error_get_last()['message']
            ));
        }

        if (!array_key_exists('X-Requested-With', $headers)
            || $headers['X-Requested-With'] !== 'XMLHttpRequest') {
            throw new DispatcherException('AjaxDispatcher Accept only AJAX requests.');
        }
    }

    /**
     * Validate the giving handlers array.
     *
     * @param array $handlers
     *
     * @return void
     * @throws DispatcherException
     */
    protected function validateHandlers($handlers)
    {
        foreach ($handlers as $method => $_handlers) {
            $this->throwExceptionIfNotValidRequestMethod($method);

            foreach ($_handlers as $name => $value) {
                $handlerkey = gettype($value);
                if (in_array($handlerkey, ['string', 'array']) || is_callable($value)) {
                    continue;
                }

                throw new DispatcherException("the type of '$name' handler value must be either a"
                    . " string/array/callable.");
            }
        }
    }

    /**
     * Gets the current request variables.
     *
     * @param string $requestMethod
     *
     * @return array
     */
    protected function getRequestVariables($requestMethod)
    {
        if ($requestMethod === 'GET') {
            return $_GET;
        }

        if (!$raw = file_get_contents("php://input")) {
            throw new DispatcherException("Unable to read the raw data from the HTTP request.");
        }

        parse_str($raw, $params);

        return $params;
    }

    /**
     * @return void
     * @throws DispatcherException
     */
    protected function handle()
    {
        foreach ($this->handlers[$this->requestMethod] as $name => $handler) {
            if ($name !== $this->context[$this->handlerName]) {
                continue;
            }

            if (is_string($handler)) {
                echo ($this->handleString($handler));
                return;
            }

            if (is_array($handler)) {
                echo ($this->handleArray($handler));
                return;
            }

            if (is_callable($handler)) {
                echo ($this->handleCallback($handler));
                return;
            }
        }

        throw new DispatcherException('No handler was found for this AJAX request.');
    }

    /**
     * Handles handlers that defined as a string.
     *
     * @param string $string
     *
     * @return mixed
     */
    protected function handleString($string)
    {
        return call_user_func($this->getCallableMethod($string));
    }

    /**
     * Handles handlers that defined as an array.
     *
     * @param array $array
     *
     * @return mixed
     */
    protected function handleArray($array)
    {
        $args = [];

        foreach (array_splice($array, 1) as $arg) {
            if (!array_key_exists($arg, $this->context)) {
                throw new DispatcherException("$arg is not exist in the request variables.");
            }
            $args[] = $this->context[$arg];
        }

        return call_user_func($this->getCallableMethod($array[0]), $args);
    }

    /**
     * Handles handlers that defined as a callback functions.
     *
     * @param callable $callback
     *
     * @return mixed
     */
    protected function handleCallback($callback)
    {
        return $this->handleException(function () use ($callback) {
            $params  = array_splice($this->context, 1);
            $indexed = array_values($params);
            return call_user_func($callback, ...$indexed);
        });
    }

    /**
     * Extract the controller and method from the giving string and return the callable
     * method from the controller object.
     *
     * @param string $string
     *
     * @return callable
     * @throws DispatcherException
     */
    protected function getCallableMethod($string)
    {
        $tokens = @explode('@', $string);

        $controllerName = $tokens[0];
        $methodName     = $tokens[1];

        if (!$controller = $this->getControllerByName(($controllerName))) {
            throw new DispatcherException("Controller class '$controllerName' not found.");
        }

        if (!method_exists($controller, $methodName)) {
            throw new DispatcherException("Controller method '$methodName' not exist in"
                . " controller '$controllerName'.");
        }

        return function ($args = []) use ($controller, $methodName) {
            return $this->handleException(function () use ($controller, $methodName, $args) {
                return call_user_func_array([$controller, $methodName], $args);
            });
        };
    }

    /**
     * Get a controller instance from the registered controllers by its name.
     *
     * @param string $name
     *
     * @return string|false
     */
    protected function getControllerByName($name)
    {
        foreach ($this->controllers as $controller) {
            $path = explode('\\', get_class($controller));
            if (array_pop($path) === $name) {
                return $controller;
            }
        }

        return false;
    }

    /**
     * handle exceptions that may throw during the callback call.
     *
     * @param callable $callback
     *
     * @return mixed
     * @throws \Exception
     */
    protected function handleException($callback)
    {
        try {
            return $callback();
        } catch (\Exception $ex) {
            if (is_callable($this->onExceptionCallback)) {
                call_user_func($this->onExceptionCallback, $ex);
                return false;
            }

            $class = get_class($ex);
            throw new $class($ex->getMessage());
        }
    }

    /**
     * @param string $method
     *
     * @return void
     * @throws DispatcherException
     */
    protected function throwExceptionIfNotValidRequestMethod($method)
    {
        if (!in_array($method, $this->HTTPMethods)) {
            throw new DispatcherException("HTTP request method '$method' not supported.");
        }
    }
}
