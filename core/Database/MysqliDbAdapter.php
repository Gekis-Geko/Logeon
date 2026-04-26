<?php

declare(strict_types=1);

namespace Core\Database;

class MysqliDbAdapter implements DbAdapterInterface
{
    /** @var \mysqli|null */
    private static $dbLink = null;
    /** @var array */
    private $config = [];

    public function __construct(array $config = [])
    {
        $default = defined('DB') ? DB['mysql'] : [];
        $this->config = !empty($config) ? $config : $default;
    }

    private function shouldReconnect(int $errno): bool
    {
        return in_array($errno, [2006, 2013], true);
    }

    private function escapeString(string $value): string
    {
        $normalized = str_replace('\\', '', $value);
        try {
            $link = $this->connect();
            return mysqli_real_escape_string($link, $normalized);
        } catch (\Throwable $e) {
            return addslashes($normalized);
        }
    }

    private function connect(): \mysqli
    {
        if (self::$dbLink instanceof \mysqli) {
            if (@self::$dbLink->ping()) {
                return self::$dbLink;
            }
            @self::$dbLink->close();
            self::$dbLink = null;
        }

        $host = (string) ($this->config['host'] ?? 'localhost');
        $user = (string) ($this->config['user'] ?? 'root');
        $pwd = (string) ($this->config['pwd'] ?? '');
        $dbName = (string) ($this->config['db_name'] ?? '');
        $port = (int) ($this->config['port'] ?? 3306);
        if ($port <= 0) {
            $port = 3306;
        }
        $socket = (string) ($this->config['socket'] ?? '');
        if ($socket === '') {
            $socket = null;
        }

        $link = @mysqli_connect($host, $user, $pwd, $dbName, $port, $socket);
        if (!$link instanceof \mysqli) {
            throw new \RuntimeException('Connessione database non disponibile');
        }

        $link->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
        $charset = (string) ($this->config['charset'] ?? 'utf8mb4');
        if (!$link->set_charset($charset)) {
            throw new \RuntimeException('Charset database non valido');
        }

        self::$dbLink = $link;
        return self::$dbLink;
    }

    private function cryptKey(): string
    {
        if (defined('DB')) {
            return (string) DB['crypt_key'];
        }

        return '';
    }

    public function query(string $sql)
    {
        $link = $this->connect();
        $res = $link->query($sql);
        if ($res === false && $this->shouldReconnect((int) $link->errno)) {
            self::$dbLink = null;
            $link = $this->connect();
            $res = $link->query($sql);
        }
        if ($res === false) {
            $message = trim((string) $link->error);
            if ($message === '') {
                $message = 'Errore database';
            }
            throw new \RuntimeException($message);
        }

        if ($res instanceof \mysqli_result) {
            return new MysqliQueryResult($res);
        }

        return new MysqliQueryResult();
    }

    /**
     * @param array<int,mixed> $params
     */
    public function queryPrepared(string $sql, array $params = [])
    {
        $stmt = $this->prepareAndExecute($sql, $params);
        $rows = $this->fetchRowsFromStatement($stmt);
        $stmt->close();

        if (empty($rows)) {
            return new MysqliQueryResult();
        }

        $tmpResult = new class($rows) extends MysqliQueryResult {
            /** @var array<int,object> */
            private $rows;

            /**
             * @param array<int,object> $rows
             */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
                parent::__construct(null);
            }

            public function first(): object|array
            {
                return $this->rows[0] ?? [];
            }

            public function fetch(): array
            {
                return $this->rows;
            }

            public function count(): int
            {
                return count($this->rows);
            }
        };

        return $tmpResult;
    }

    /**
     * @param array<int,mixed> $params
     */
    public function executePrepared(string $sql, array $params = []): bool
    {
        $stmt = $this->prepareAndExecute($sql, $params);
        $stmt->close();
        return true;
    }

    /**
     * @param array<int,mixed> $params
     * @return mixed
     */
    public function fetchOnePrepared(string $sql, array $params = [])
    {
        $rows = $this->fetchAllPrepared($sql, $params);
        return $rows[0] ?? [];
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,mixed>
     */
    public function fetchAllPrepared(string $sql, array $params = []): array
    {
        $stmt = $this->prepareAndExecute($sql, $params);
        $rows = $this->fetchRowsFromStatement($stmt);
        $stmt->close();

        return $rows;
    }

    public function lastInsertId(): int
    {
        $link = $this->connect();
        return (int) $link->insert_id;
    }

    public function safe(mixed $value, bool $quotes = true): string|array
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        if (is_object($value)) {
            $value = json_encode($value);
        }
        if (is_array($value)) {
            $array = [];
            foreach ($value as $elem) {
                $v = $this->escapeString(trim((string) $elem));
                $array[] = ($quotes) ? "'" . $v . "'" : $v;
            }

            return $array;
        }

        $stringValue = trim((string) $value);
        $escaped = $this->escapeString($stringValue);
        return ($quotes) ? "'" . $escaped . "'" : $escaped;
    }

    public function ifNotNull(mixed $value, mixed $altvalue = false): mixed
    {
        if (!isset($value)) {
            return 'NULL';
        }

        if (!$altvalue) {
            return $this->safe($value);
        }

        return $altvalue;
    }

    public function crypt(mixed $value): string
    {
        return ' AES_ENCRYPT(' . $this->safe($value) . ', "' . $this->cryptKey() . '") ';
    }

    public function decrypt(mixed $value, mixed $alias = false): string
    {
        $as = '';
        if ($alias !== false && $alias !== null && trim((string) $alias) !== '') {
            $aliasName = ($alias === true) ? (string) $value : (string) $alias;
            $aliasName = trim($aliasName);
            if ($aliasName !== '') {
                $as = ' as ' . $aliasName;
            }
        }
        return ' AES_DECRYPT(' . $value . ', "' . $this->cryptKey() . '") ' . $as;
    }

    /**
     * @param array<int,mixed> $params
     * @return array{0: \mysqli_stmt|null, 1: string, 2: int}
     */
    private function tryPrepareExecute(string $sql, array $params): array
    {
        $link = $this->connect();
        $stmt = $link->prepare($sql);
        if (!$stmt instanceof \mysqli_stmt) {
            $message = trim((string) $link->error);
            if ($message === '') {
                $message = 'Errore database';
            }
            return [null, $message, (int) $link->errno];
        }

        if (!empty($params)) {
            $this->bindParams($stmt, $params);
        }

        if ($stmt->execute()) {
            return [$stmt, '', 0];
        }

        $message = trim((string) $stmt->error);
        if ($message === '') {
            $message = 'Errore database';
        }
        $errno = (int) $stmt->errno;
        $stmt->close();
        return [null, $message, $errno];
    }

    /**
     * @param array<int,mixed> $params
     */
    private function prepareAndExecute(string $sql, array $params = []): \mysqli_stmt
    {
        [$stmt, $message, $errno] = $this->tryPrepareExecute($sql, $params);
        if ($stmt instanceof \mysqli_stmt) {
            return $stmt;
        }

        if (!$this->shouldReconnect($errno)) {
            throw new \RuntimeException($message);
        }

        self::$dbLink = null;
        [$stmt, $message,] = $this->tryPrepareExecute($sql, $params);
        if ($stmt instanceof \mysqli_stmt) {
            return $stmt;
        }

        throw new \RuntimeException($message);
    }

    /**
     * @param array<int,mixed> $params
     */
    private function bindParams(\mysqli_stmt $stmt, array $params): void
    {
        $types = '';
        $values = [];

        foreach ($params as $value) {
            if (is_bool($value)) {
                $types .= 'i';
                $values[] = $value ? 1 : 0;
                continue;
            }

            if (is_int($value)) {
                $types .= 'i';
                $values[] = $value;
                continue;
            }

            if (is_float($value)) {
                $types .= 'd';
                $values[] = $value;
                continue;
            }

            if ($value === null) {
                $types .= 's';
                $values[] = null;
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $types .= 's';
                $values[] = json_encode($value);
                continue;
            }

            $types .= 's';
            $values[] = (string) $value;
        }

        $bindArgs = [];
        $bindArgs[] = &$types;
        foreach ($values as $index => $unused) {
            $bindArgs[] = &$values[$index];
        }

        $ok = call_user_func_array([$stmt, 'bind_param'], $bindArgs);
        if ($ok === false) {
            $message = trim((string) $stmt->error);
            if ($message === '') {
                $message = 'Errore database';
            }
            throw new \RuntimeException($message);
        }
    }

    /**
     * @return array<int,object>
     */
    private function fetchRowsFromStatement(\mysqli_stmt $stmt): array
    {
        $result = $stmt->get_result();

        if ($result instanceof \mysqli_result) {
            $rows = [];
            while ($row = $result->fetch_object()) {
                $rows[] = $row;
            }
            $result->free();

            return $rows;
        }

        $metadata = $stmt->result_metadata();
        if (!$metadata instanceof \mysqli_result) {
            return [];
        }

        $fields = [];
        $rowData = [];
        $bind = [];
        while ($field = $metadata->fetch_field()) {
            $name = $field->name;
            $fields[] = $name;
            $rowData[$name] = null;
            $bind[] = &$rowData[$name];
        }

        if (!empty($bind)) {
            call_user_func_array([$stmt, 'bind_result'], $bind);
        }

        $rows = [];
        while ($stmt->fetch()) {
            $copy = [];
            foreach ($fields as $name) {
                $copy[$name] = $rowData[$name];
            }
            $rows[] = (object) $copy;
        }

        $metadata->free();
        return $rows;
    }
}
