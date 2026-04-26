<?php

declare(strict_types=1);

namespace Core\Http;

class RequestData
{
    /** @var array<string,mixed> */
    private $query = [];
    /** @var array<string,mixed> */
    private $post = [];
    /** @var array<string,mixed> */
    private $request = [];
    /** @var array<string,mixed> */
    private $server = [];
    /** @var string */
    private $rawBody = '';

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $request
     * @param array<string,mixed> $server
     */
    public function __construct(array $query = [], array $post = [], array $request = [], array $server = [], string $rawBody = '')
    {
        $this->query = $query;
        $this->post = $post;
        $this->request = $request;
        $this->server = $server;
        $this->rawBody = $rawBody;
    }

    public static function fromGlobals(): self
    {
        $rawBody = static::readRawBody();
        $globals = static::readGlobals();

        return new self(
            $globals['get'],
            $globals['post'],
            $globals['request'],
            $globals['server'],
            $rawBody,
        );
    }

    /**
     * @return array{get: array<string,mixed>, post: array<string,mixed>, request: array<string,mixed>, server: array<string,mixed>}
     */
    public static function readGlobals(): array
    {
        return [
            'get' => $_GET,
            'post' => $_POST,
            'request' => $_REQUEST,
            'server' => $_SERVER,
        ];
    }

    public static function readRawBody(): string
    {
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody)) {
            return '';
        }

        return $rawBody;
    }

    /**
     * @return mixed
     */
    public function query(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }

        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    /**
     * @return mixed
     */
    public function post(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->post;
        }

        return array_key_exists($key, $this->post) ? $this->post[$key] : $default;
    }

    /**
     * @return mixed
     */
    public function input(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->request;
        }

        return array_key_exists($key, $this->request) ? $this->request[$key] : $default;
    }

    /**
     * @return mixed
     */
    public function server(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->server;
        }

        return array_key_exists($key, $this->server) ? $this->server[$key] : $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Decode a JSON string contained in a POST field.
     * Missing field returns default; invalid JSON returns null.
     *
     * @return mixed
     */
    public function postJson(string $key = 'data', $default = null, bool $assoc = true)
    {
        if (!array_key_exists($key, $this->post)) {
            return $default;
        }

        $value = $this->post[$key];
        if (!is_string($value)) {
            return null;
        }

        return $this->decodeJson($value, $assoc);
    }

    /**
     * @return mixed
     */
    public function jsonBody($default = null, bool $assoc = true)
    {
        if ($this->rawBody === '') {
            return $default;
        }

        return $this->decodeJson($this->rawBody, $assoc);
    }

    /**
     * @return mixed
     */
    private function decodeJson(string $json, bool $assoc)
    {
        $decoded = json_decode($json, $assoc);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }
}
