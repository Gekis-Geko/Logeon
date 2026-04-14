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
    private $preRouteError = false;
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
        $this->preRouteError = false;
        $this->_requestedMethod = $this->getRequestMethod();
        if (isset($this->_beforeRoutes[$this->_requestedMethod])) {
            $this->handle($this->_beforeRoutes[$this->_requestedMethod]);
            if ($this->preRouteError) {
                $server = RequestContext::server();
                if (($server['REQUEST_METHOD'] ?? '') == 'HEAD') {
                    ob_end_clean();
                }
                return false;
            }
        }

        $numHandled = 0;
        if (isset($this->_afterRoutes[$this->_requestedMethod])) {
            $numHandled = $this->handle($this->_afterRoutes[$this->_requestedMethod], true);
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

    private function handle($routes, $quitAfterRun = false)
    {
        $numHandled = 0;
        $uri = $this->getCurrentUri();
        foreach ($routes as $route) {
            // Route params must accept full URL-safe segments (e.g. "character-attributes").
            $route['pattern'] = preg_replace('/{([A-Za-z]*?)}/', '([^\/]+)', $route['pattern']);
            if (preg_match_all('#^' . $route['pattern'] . '$#', $uri, $matches, PREG_OFFSET_CAPTURE)) {
                $matches = array_slice($matches, 1);
                $params = array_map(function ($match, $index) use ($matches) {
                    if (isset($matches[$index + 1]) && isset($matches[$index + 1][0]) && is_array($matches[$index + 1][0])) {
                        return trim(substr($match[0][0], 0, $matches[$index + 1][0][1] - $match[0][1]), '/');
                    } else {
                        return isset($match[0][0]) ? trim($match[0][0], '/') : null;
                    }
                }, $matches, array_keys($matches));

                $this->_currentRouteOptions = isset($route['options']) && is_array($route['options']) ? $route['options'] : [];
                self::$currentRouteOptions = $this->_currentRouteOptions;
                try {
                    $this->invoke($route['fn'], $params);
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
        return $numHandled;
    }

    private function invoke($fn, $params = [])
    {
        try {
            if (is_callable($fn)) {
                call_user_func_array($fn, $params);
                return;
            }

            if (stripos($fn, '@') !== false) {
                list($controller, $method) = explode('@', $fn);
                if ($this->getNamespace() !== '') {
                    $controller = $this->getNamespace() . '\\' . $controller;
                }
                if (class_exists($controller)) {
                    if (call_user_func_array([new $controller(), $method], $params) === false) {
                        if (forward_static_call_array([$controller, $method], $params) === false)
                        ;
                    }
                }
            }
        } catch (AppError $e) {
            $this->preRouteError = true;
            if ($this->shouldRespondJsonForAppError()) {
                ErrorResponder::fromThrowable($e, false);
                return;
            }

            ErrorResponder::fromThrowableHtml($e);
            return;
        } catch (\Throwable $e) {
            $this->preRouteError = true;
            $this->logUnhandledThrowable($e);
            if ($this->shouldRespondJsonForAppError()) {
                ErrorResponder::fromThrowable($e, true);
                return;
            }

            ErrorResponder::fromThrowableHtml($e);
            return;
        }
    }

    private function logUnhandledThrowable(\Throwable $e): void
    {
        error_log(
            '[Logeon][Router] Unhandled throwable: '
            . $e->getMessage()
            . ' in ' . $e->getFile()
            . ':' . $e->getLine(),
        );

        if (defined('CONFIG') && is_array(CONFIG) && !empty(CONFIG['debug'])) {
            error_log($e->getTraceAsString());
        }
    }

    private function shouldRespondJsonForAppError()
    {
        return self::shouldRespondJson();
    }

    private function emitNotFound(): void
    {
        $error = AppError::notFound('Pagina non trovata');
        if ($this->shouldRespondJsonForAppError()) {
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
