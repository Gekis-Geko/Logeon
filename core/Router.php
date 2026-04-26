<?php

declare(strict_types=1);

namespace Core;

use Core\Http\AppError;
use Core\Http\ErrorResponder;
use Core\Http\RequestContext;

class Router
{
    private $_afterRoutes = [];
    private $_beforeRoutes = [];
    private $_baseRoute = '';
    private $_requestedMethod = '';
    private $_serverBasePath;
    private $_namespace = '';
    private $_currentRouteOptions = [];
    private static $currentRouteOptions = [];
    protected $__notFoundCallback;

    public function before($methods, $pattern, $fn)
    {
        $pattern = $this->_baseRoute . '/' . trim($pattern, '/');
        $pattern = $this->_baseRoute ? rtrim($pattern, '/') : $pattern;
        foreach (explode('|', $methods) as $method) {
            $this->_beforeRoutes[$method][] = [
                'pattern' => $pattern,
                'fn' => $fn,
            ];
        }
    }

    public function match($methods, $pattern, $fn)
    {
        $this->matchWithOptions($methods, $pattern, $fn, []);
    }

    public function apiMatch($methods, $pattern, $fn)
    {
        $this->matchWithOptions($methods, $pattern, $fn, [
            'error_format' => 'json',
        ]);
    }

    public function apiAll($pattern, $fn)
    {
        $this->apiMatch('GET|POST|PUT|DELETE|OPTIONS|PATCH|HEAD', $pattern, $fn);
    }

    public function apiGet($pattern, $fn)
    {
        $this->apiMatch('GET', $pattern, $fn);
    }

    public function apiPost($pattern, $fn)
    {
        $this->apiMatch('POST', $pattern, $fn);
    }

    public function apiPatch($pattern, $fn)
    {
        $this->apiMatch('PATCH', $pattern, $fn);
    }

    public function apiDelete($pattern, $fn)
    {
        $this->apiMatch('DELETE', $pattern, $fn);
    }

    public function apiPut($pattern, $fn)
    {
        $this->apiMatch('PUT', $pattern, $fn);
    }

    public function apiOptions($pattern, $fn)
    {
        $this->apiMatch('OPTIONS', $pattern, $fn);
    }

    private function matchWithOptions($methods, $pattern, $fn, $options = [])
    {
        $pattern = $this->_baseRoute . '/' . trim($pattern, '/');
        $pattern = $this->_baseRoute ? rtrim($pattern, '/') : $pattern;
        foreach (explode('|', $methods) as $method) {
            $this->_afterRoutes[$method][] = [
                'pattern' => $pattern,
                'fn' => $fn,
                'options' => is_array($options) ? $options : [],
            ];
        }
    }

    public function all($pattern, $fn)
    {
        $this->match('GET|POST|PUT|DELETE|OPTIONS|PATCH|HEAD', $pattern, $fn);
    }

    public function get($pattern, $fn)
    {
        $this->match('GET', $pattern, $fn);
    }

    public function post($pattern, $fn)
    {
        $this->match('POST', $pattern, $fn);
    }

    public function patch($pattern, $fn)
    {
        $this->match('PATCH', $pattern, $fn);
    }

    public function delete($pattern, $fn)
    {
        $this->match('DELETE', $pattern, $fn);
    }

    public function put($pattern, $fn)
    {
        $this->match('PUT', $pattern, $fn);
    }

    public function options($pattern, $fn)
    {
        $this->match('OPTIONS', $pattern, $fn);
    }

    public function mount($_baseRoute, $fn)
    {
        $curBaseRoute = $this->_baseRoute;
        $this->_baseRoute = $curBaseRoute . $_baseRoute;
        call_user_func($fn);
        $this->_baseRoute = $curBaseRoute;

        return $this;
    }

    public function group($_baseRoute, $fn)
    {
        return $this->mount($_baseRoute, function () use ($fn) {
            $fn($this);
        });
    }

    public function getRequestHeaders()
    {
        return RequestContext::headers();
    }

    public function getRequestMethod()
    {
        $server = RequestContext::server();
        $method = $server['REQUEST_METHOD'] ?? '';
        if ($method === '') {
            $method = 'GET';
        }
        if ($method == 'HEAD') {
            ob_start();
            $method = 'GET';
        } elseif ($method == 'POST') {
            $headers = $this->getRequestHeaders();
            if (isset($headers['X-HTTP-Method-Override']) && in_array($headers['X-HTTP-Method-Override'], ['PUT', 'DELETE', 'PATCH'])) {
                $method = $headers['X-HTTP-Method-Override'];
            }
        }

        return $method;
    }

    public function setNamespace($_namespace)
    {
        if (is_string($_namespace)) {
            $this->_namespace = $_namespace;
        }
    }

    public function getNamespace()
    {
        return $this->_namespace;
    }

    public function run($callback = null)
    {
        $this->_requestedMethod = $this->getRequestMethod();
        if (isset($this->_beforeRoutes[$this->_requestedMethod])) {
            $beforeRouteResult = $this->handle($this->_beforeRoutes[$this->_requestedMethod]);
            if ($beforeRouteResult['hadError']) {
                $server = RequestContext::server();
                if (($server['REQUEST_METHOD'] ?? '') == 'HEAD') {
                    ob_end_clean();
                }
                return false;
            }
        }

        $numHandled = 0;
        if (isset($this->_afterRoutes[$this->_requestedMethod])) {
            $afterRouteResult = $this->handle($this->_afterRoutes[$this->_requestedMethod], true);
            $numHandled = $afterRouteResult['numHandled'];
        }

        if ($numHandled === 0) {
            if ($this->__notFoundCallback) {
                $this->invoke($this->__notFoundCallback);
            } else {
                $this->emitNotFound();
            }
        } else {
            if ($callback && is_callable($callback)) {
                $callback();
            }
        }

        $server = RequestContext::server();
        if (($server['REQUEST_METHOD'] ?? '') == 'HEAD') {
            ob_end_clean();
        }
        return $numHandled !== 0;
    }

    public function set404($fn)
    {
        $this->__notFoundCallback = $fn;
    }

    /**
     * @return array{numHandled: int, hadError: bool}
     */
    private function handle($routes, $quitAfterRun = false)
    {
        $numHandled = 0;
        $hadError = false;
        $uri = $this->getCurrentUri();
        foreach ($routes as $route) {
            // Route params must accept full URL-safe segments (e.g. "character-attributes").
            $route['pattern'] = preg_replace('/{([A-Za-z]*?)}/', '([^\/]+)', $route['pattern']);
            if (preg_match_all('#^' . $route['pattern'] . '$#', $uri, $matches, PREG_OFFSET_CAPTURE)) {
                $matches = array_slice($matches, 1);
                $params = array_map(function ($match, $index) use ($matches) {
                    if (isset($matches[$index + 1]) && isset($matches[$index + 1][0])) {
                        return trim(substr($match[0][0], 0, $matches[$index + 1][0][1] - $match[0][1]), '/');
                    } else {
                        return isset($match[0][0]) ? trim($match[0][0], '/') : null;
                    }
                }, $matches, array_keys($matches));

                $this->_currentRouteOptions = isset($route['options']) && is_array($route['options']) ? $route['options'] : [];
                self::$currentRouteOptions = $this->_currentRouteOptions;
                try {
                    $invokeSucceeded = $this->invoke($route['fn'], $params);
                    if (!$invokeSucceeded) {
                        $hadError = true;
                    }
                } finally {
                    $this->_currentRouteOptions = [];
                    self::$currentRouteOptions = [];
                }
                ++$numHandled;
                if ($quitAfterRun) {
                    break;
                }
            }
        }
        return [
            'numHandled' => $numHandled,
            'hadError' => $hadError,
        ];
    }

    private function invoke($fn, $params = []): bool
    {
        try {
            $handler = $this->resolveHandler($fn);
            if ($handler === null) {
                return true;
            }

            $this->executeHandler($handler, $params);
            return true;
        } catch (AppError $e) {
            $this->respondToThrowable($e, false);
            return false;
        } catch (\Throwable $e) {
            $this->logUnhandledThrowable($e);
            $this->respondToThrowable($e, true);
            return false;
        }
    }

    /**
     * @param mixed $fn
     * @return array{type: 'callable', callable: callable}|array{type: 'legacy_controller', controller: string, method: string}|null
     */
    private function resolveHandler($fn)
    {
        if (is_callable($fn)) {
            return [
                'type' => 'callable',
                'callable' => $fn,
            ];
        }

        if (!is_string($fn) || stripos($fn, '@') === false) {
            return null;
        }

        list($controller, $method) = explode('@', $fn);
        if ($this->getNamespace() !== '') {
            $controller = $this->getNamespace() . '\\' . $controller;
        }

        if (!class_exists($controller)) {
            return null;
        }

        return [
            'type' => 'legacy_controller',
            'controller' => $controller,
            'method' => $method,
        ];
    }

    /**
     * @param array{type: 'callable', callable: callable}|array{type: 'legacy_controller', controller: string, method: string} $handler
     */
    private function executeHandler(array $handler, array $params): void
    {
        if ($handler['type'] === 'callable') {
            call_user_func_array($handler['callable'], $params);
            return;
        }

        $this->invokeLegacyControllerHandler($handler['controller'], $handler['method'], $params);
    }

    /**
     * Legacy compatibility path:
     * 1) invoke instance method
     * 2) if it returns false, fallback to static invocation
     *
     * @param array<int,mixed> $params
     */
    private function invokeLegacyControllerHandler(string $controller, string $method, array $params): void
    {
        if (!method_exists($controller, $method)) {
            return;
        }

        $instanceResult = call_user_func_array([new $controller(), $method], $params);
        if ($instanceResult !== false) {
            return;
        }

        $this->invokeLegacyControllerStaticFallback($controller, $method, $params);
    }

    /**
     * @param array<int,mixed> $params
     */
    private function invokeLegacyControllerStaticFallback(string $controller, string $method, array $params): void
    {
        if (!is_callable([$controller, $method])) {
            return;
        }

        forward_static_call_array([$controller, $method], $params);
    }

    private function respondToThrowable(\Throwable $e, bool $legacyOnUnknown): void
    {
        if ($this->shouldRespondJsonForErrors()) {
            ErrorResponder::fromThrowable($e, $legacyOnUnknown);
            return;
        }

        ErrorResponder::fromThrowableHtml($e);
    }

    private function logUnhandledThrowable(\Throwable $e): void
    {
        error_log(
            '[Logeon][Router] Unhandled throwable: '
            . $e->getMessage()
            . ' in ' . $e->getFile()
            . ':' . $e->getLine(),
        );

        $config = $this->runtimeConfig();
        if ($config !== null && !empty($config['debug'])) {
            error_log($e->getTraceAsString());
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function runtimeConfig(): ?array
    {
        if (!defined('CONFIG')) {
            return null;
        }

        /** @var array<string,mixed> $config */
        $config = constant('CONFIG');
        return $config;
    }

    private function shouldRespondJsonForErrors()
    {
        return self::shouldRespondJson();
    }

    private function emitNotFound(): void
    {
        $error = AppError::notFound('Pagina non trovata');
        if ($this->shouldRespondJsonForErrors()) {
            ErrorResponder::fromThrowable($error, false);
            return;
        }

        ErrorResponder::fromThrowableHtml($error);
    }

    public static function shouldRespondJson(): bool
    {
        $options = self::$currentRouteOptions;
        $format = '';
        if (isset($options['error_format'])) {
            $format = strtolower((string) $options['error_format']);
        }
        if ($format === 'json') {
            return true;
        }
        return RequestContext::wantsJson();
    }

    protected function getCurrentUri()
    {
        $requestUri = RequestContext::server()['REQUEST_URI'] ?? '';
        $uri = substr($requestUri, strlen($this->getBasePath()));
        if (strstr($uri, '?')) {
            $uri = substr($uri, 0, strpos($uri, '?'));
        }
        return '/' . trim($uri, '/');
    }

    public static function currentUri()
    {
        $server = RequestContext::server();
        $scriptName = $server['SCRIPT_NAME'] ?? '';
        $requestUri = $server['REQUEST_URI'] ?? '';
        $serverBasePath = implode('/', array_slice(explode('/', $scriptName), 0, -1)) . '/';

        $uri = substr($requestUri, strlen($serverBasePath));
        if (strstr($uri, '?')) {
            $uri = substr($uri, 0, strpos($uri, '?'));
        }
        return '/' . trim($uri, '/');
    }

    protected function getBasePath()
    {
        if ($this->_serverBasePath === null) {
            $scriptName = RequestContext::server()['SCRIPT_NAME'] ?? '';
            $this->_serverBasePath = implode('/', array_slice(explode('/', $scriptName), 0, -1)) . '/';
        }

        return $this->_serverBasePath;
    }

}
