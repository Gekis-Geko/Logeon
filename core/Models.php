<?php

declare(strict_types=1);

namespace Core;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;

use Core\Logging\LoggerInterface;
use Core\Models\ListQuery;
use Core\Models\ModelAudit;
use Core\Models\ModelResponder;

class Models
{
    protected $table = null;
    protected $primary_key = null;
    protected $fillable = [];
    protected $joins = null;
    protected $enableSoftDelete = false;
    protected $allow_raw_conditions = false;
    /** @var LoggerInterface|null */
    protected static $loggerAdapter = null;
    /** @var DbAdapterInterface|null */
    protected static $dbAdapter = null;
    /** @var ModelResponder|null */
    private $modelResponder = null;
    /** @var ModelAudit|null */
    private $modelAudit = null;

    public static function setDbAdapter(DbAdapterInterface $adapter = null): void
    {
        static::$dbAdapter = $adapter;
    }

    protected static function db(): DbAdapterInterface
    {
        if (static::$dbAdapter instanceof DbAdapterInterface) {
            return static::$dbAdapter;
        }

        static::$dbAdapter = DbAdapterFactory::createFromConfig();
        return static::$dbAdapter;
    }

    public static function query($sql)
    {
        return static::db()->query((string) $sql);
    }

    public static function safe(mixed $value, bool $quotes = true): string|array
    {
        return static::db()->safe($value, $quotes);
    }

    public static function ifNotNull(mixed $value, mixed $altvalue = false): mixed
    {
        return static::db()->ifNotNull($value, $altvalue);
    }

    public static function crypt(mixed $value): string
    {
        return static::db()->crypt($value);
    }

    public static function decrypt(mixed $value, mixed $alias = false): string
    {
        return static::db()->decrypt($value, $alias);
    }

    private function sanitizeIdentifier($value, $fallback = '')
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }
        if (!preg_match('/^[A-Za-z0-9_\\.]+$/', $value)) {
            return $fallback;
        }
        return $value;
    }

    private function sanitizeOperator($operator = '=')
    {
        $operator = strtoupper(trim((string) $operator));
        $allowed = ['=', '!=', '<>', '>', '>=', '<', '<='];
        return in_array($operator, $allowed, true) ? $operator : '=';
    }

    private function isSafeRawCondition($value)
    {
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (preg_match('/(;|--|\\/\\*|\\*\\/|\\bUNION\\b|\\bDROP\\b|\\bTRUNCATE\\b|\\bALTER\\b|\\bCREATE\\b)/i', $value)) {
            return false;
        }
        return true;
    }

    private function sanitizeOrderBy($order_fields = '')
    {
        $order_fields = trim((string) $order_fields);
        if ($order_fields === '') {
            return '';
        }

        $parts = explode(',', $order_fields);
        $safe = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $tokens = preg_split('/\s+/', $part);
            $field = $this->sanitizeIdentifier($tokens[0] ?? '', '');
            if ($field === '') {
                continue;
            }

            $dir = strtoupper($tokens[1] ?? 'ASC');
            if (!in_array($dir, ['ASC', 'DESC'], true)) {
                $dir = 'ASC';
            }

            $safe[] = $field . ' ' . $dir;
        }

        if (empty($safe)) {
            return '';
        }

        return ' ORDER BY ' . implode(', ', $safe);
    }

    private function listQuery(): ListQuery
    {
        return new ListQuery(
            $this->table,
            $this->primary_key,
            $this->fillable,
            $this->joins,
            $this->allow_raw_conditions,
            function ($identifier, $fallback = '') {
                return $this->sanitizeIdentifier($identifier, $fallback);
            },
            function ($value) {
                return $this->isSafeRawCondition($value);
            },
            function ($value, $quotes = true) {
                return static::safe($value, $quotes);
            },
            function ($value, $alias = false) {
                return static::decrypt($value, $alias);
            },
        );
    }

    private function responder(): ModelResponder
    {
        if ($this->modelResponder instanceof ModelResponder) {
            return $this->modelResponder;
        }

        $this->modelResponder = new ModelResponder();
        return $this->modelResponder;
    }

    private function audit(): ModelAudit
    {
        if ($this->modelAudit instanceof ModelAudit) {
            return $this->modelAudit;
        }

        $this->modelAudit = new ModelAudit();
        return $this->modelAudit;
    }

    private function requestData($default = null)
    {
        $request = RequestData::fromGlobals();
        return $request->postJson('data', $default, false);
    }

    protected static function loggerAdapter(): LoggerInterface
    {
        if (static::$loggerAdapter instanceof LoggerInterface) {
            return static::$loggerAdapter;
        }

        static::$loggerAdapter = \Core\AppContext::logger();
        return static::$loggerAdapter;
    }

    protected function trace($message, $context = false): void
    {
        static::loggerAdapter()->trace($message, $context);
    }

    public function get($field = '', $value = null, $operator = '=', $order_fields = '', $echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $field = (null == $field) ? $this->primary_key : $field;
        $field = $this->sanitizeIdentifier($field, $this->primary_key);
        if (strpos($field, '.') === false) {
            $field = $this->table . '.' . $field;
        }

        $operator = $this->sanitizeOperator($operator);
        $order_by = $this->sanitizeOrderBy($order_fields);
        $joins = (!empty($this->joins)) ? implode('', $this->joins) : '';

        $post = (null != $value) ? $value : $this->requestData((object) []);

        $dataset = static::query(
            'SELECT ' . implode(', ', $this->fillable) . ' FROM ' . $this->table . $joins . ' WHERE ' . $field . ' ' . $operator . ' ' . static::safe($post) . $order_by,
        )->first();

        $response = $this->responder()->itemResponse($dataset);

        $this->audit()->writeItem($response);

        $this->responder()->emitJson($response, $echo);

        return $response;
    }

    public function getByID($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $post = $this->requestData();
        if ($post === null || !isset($post->{$this->primary_key})) {
            throw AppError::validation('ID non valido');
        }

        $joins = (!empty($this->joins)) ? implode('', $this->joins) : '';
        $id = static::safe($post->{$this->primary_key});

        $dataset = static::query(
            'SELECT ' . implode(', ', $this->fillable) . ' FROM ' . $this->table . $joins . ' WHERE ' . $this->table . '.' . $this->primary_key . ' = ' . $id,
        )->first();

        $response = $this->responder()->itemResponse($dataset);

        $this->audit()->writeItem($response);

        $this->responder()->emitJson($response, $echo);

        return $response;
    }

    public function list($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $post = $this->requestData((object) []);
        if ($post === null) {
            $post = (object) [];
        }

        return $this->listWithPayload($post, $echo);
    }

    protected function listWithPayload($post, $echo = true)
    {
        if (empty($this->table)) {
            ResponseEmitter::emit(ApiResponse::json([
                'error' => 'Tabella non dichiarata',
            ]));

            return $this;
        }

        if ($post === null || !is_object($post)) {
            $post = (object) [];
        }

        $query = [];
        $joins = (!empty($this->joins)) ? implode('', $this->joins) : '';

        $listQuery = $this->listQuery();
        if (!empty($post->query)) {
            $query = $listQuery->buildFilters($post->query);
        }

        $orderInfo = $listQuery->normalizeOrder($post->orderBy ?? null);
        $post->orderBy = $orderInfo['raw'];
        $order = $orderInfo['sql'];

        $where = (!empty($query)) ? ' WHERE ((' . implode(') AND (', $query) . '))' : '';
        $pagination = $listQuery->normalizePagination($post);
        $page = $pagination['page'];
        $results = $pagination['results'];
        $limit = $pagination['limit'];

        $useCache = $listQuery->shouldUseCache($post);
        $cacheKey = null;
        $cacheTtl = $listQuery->getCacheTtl($post);
        if ($useCache) {
            $cacheKey = $listQuery->buildCacheKey($where, $order, $limit);
            $cached = \Core\Cache::get($cacheKey);
            if ($cached !== null) {
                $this->responder()->emitJson($cached, $echo);
                return $cached;
            }
        }

        $dataset = static::query('SELECT ' . implode(', ', $this->fillable) . ' FROM ' . $this->table . $joins . $where . $order . $limit)->fetch();
        $count = static::query('SELECT COUNT(DISTINCT ' . $this->table . '.' . $this->primary_key . ') AS count FROM ' . $this->table . $joins . $where)->first();

        $response = $listQuery->buildResponse($dataset, $count, $post, $page, $results);

        $this->audit()->writeList($response);

        if ($useCache && $cacheKey) {
            \Core\Cache::set($cacheKey, $response, $cacheTtl);
        }

        $this->responder()->emitJson($response, $echo);

        return $response;
    }

    public function delete($operator = '=')
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $post = $this->requestData();
        if ($post === null || !isset($post->id)) {
            throw AppError::validation('ID non valido');
        }

        if (true == $this->enableSoftDelete) {
            $this->softDelete($post->id, $operator);

            return $this;
        }

        $safeOperator = $this->sanitizeOperator($operator);
        $response = static::query(
            'DELETE FROM ' . $this->table . ' WHERE ' . $this->primary_key . ' ' . $safeOperator . ' ' . static::safe($post->id),
        );

        $this->audit()->writeDelete(['id' => $post->id]);

        $this->responder()->emitJson($this->responder()->deleteResponse(), true, 200, JSON_FORCE_OBJECT);

        return $response;
    }

    private function softDelete($id, $operator = '=')
    {
        if (false == $this->enableSoftDelete) {
            return $this;
        }

        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $safeOperator = $this->sanitizeOperator($operator);
        static::query('UPDATE ' . $this->table . ' SET date_deleted = NOW() WHERE ' . $this->primary_key . ' ' . $safeOperator . ' ' . static::safe($id));

        $this->audit()->writeDelete(['id' => $id]);

        return $this;
    }

    public function restore($id, $operator = '=')
    {
        if (false == $this->enableSoftDelete) {
            return $this;
        }

        $this->trace('Richiamato il metodo: ' . __METHOD__);

        $safeOperator = $this->sanitizeOperator($operator);
        static::query('UPDATE ' . $this->table . ' SET date_deleted = NULL WHERE ' . $this->primary_key . ' ' . $safeOperator . ' ' . static::safe($id));

        $this->audit()->writeDelete(['id' => $id]);

        return $this;
    }

    public function truncate()
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);

        static::query('TRUNCATE ' . $this->table);

        $this->audit()->writeTruncate(['table' => $this->table]);

        return $this;
    }

    public static function selectRaw($sql = null, $echo = true)
    {
        if (null == $sql) {
            return;
        }

        static::loggerAdapter()->trace('Richiamato il metodo: ' . __METHOD__);

        $dataset = static::query($sql)->fetch();

        if (null == $dataset) {
            return false;
        }

        /** @phpstan-ignore new.static */
        $instance = new static();
        $response = $instance->responder()->itemResponse($dataset);
        $instance->audit()->writeItem($response);
        if (true == $echo) {
            ResponseEmitter::emit(ApiResponse::json($dataset));
        }

        return $dataset;
    }

    protected static function getTable()
    {
        /** @phpstan-ignore property.staticAccess */
        return self::$table;
    }

    protected static function getPrimaryKey()
    {
        /** @phpstan-ignore property.staticAccess */
        return self::$primary_key;
    }

    protected static function getFillableFields()
    {
        /** @phpstan-ignore property.staticAccess */
        return self::$fillable;
    }

    protected function cleanDataset($dataset)
    {
        //do nothing
        return $dataset;
    }

    protected function checkDataset($dataset)
    {
        //do nothing
    }

}


