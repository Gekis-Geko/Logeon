<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

/**
 * Fallback admin service for the core single-currency mode.
 * Multi-currency CRUD lives in the external module.
 */
class CurrencyAdminService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): bool
    {
        return $this->db->executePrepared($sql, $params);
    }

    public function list(object $data): array
    {
        $query = is_object($data->query ?? null) ? $data->query : (object) [];
        $where = [];
        $params = [];

        $code = strtoupper(trim((string) ($query->code ?? '')));
        if ($code !== '') {
            $where[] = 'UPPER(c.code) LIKE ?';
            $params[] = '%' . $code . '%';
        }

        $name = trim((string) ($query->name ?? ''));
        if ($name !== '') {
            $where[] = 'c.name LIKE ?';
            $params[] = '%' . $name . '%';
        }

        $activeFilter = $query->status ?? ($query->is_active ?? null);
        if ($activeFilter !== null && $activeFilter !== '') {
            $where[] = 'c.is_active = ?';
            $params[] = ((int) $activeFilter === 1) ? 1 : 0;
        }

        $defaultFilter = $query->is_default ?? null;
        if ($defaultFilter !== null && $defaultFilter !== '') {
            $where[] = 'c.is_default = ?';
            $params[] = ((int) $defaultFilter === 1) ? 1 : 0;
        }

        $whereSql = $where !== [] ? (' WHERE ' . implode(' AND ', $where)) : '';
        $results = (int) ($data->results ?? ($data->limit ?? 20));
        if ($results <= 0) {
            $results = 20;
        }
        if ($results > 200) {
            $results = 200;
        }

        $page = (int) ($data->page ?? 1);
        if ($page <= 0) {
            $page = 1;
        }
        $offset = ($page - 1) * $results;
        [$orderField, $orderDirection] = $this->normalizeOrder((string) ($data->orderBy ?? ''));

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS tot
             FROM currencies c' . $whereSql,
            $params,
        );
        $total = (int) ($countRow->tot ?? 0);

        $listSql = 'SELECT c.id, c.code, c.name, c.symbol, c.image, c.is_default, c.is_active
                    FROM currencies c'
            . $whereSql
            . ' ORDER BY ' . $orderField . ' ' . $orderDirection . ', c.id ASC
                LIMIT ' . (int) $offset . ', ' . (int) $results;

        return [
            'properties' => [
                'page' => $page,
                'results_page' => $results,
                'tot' => $total,
                'capabilities' => [
                    'can_create' => 0,
                    'can_delete' => 0,
                    'single_currency_mode' => 1,
                ],
            ],
            'dataset' => $this->fetchPrepared($listSql, $params),
        ];
    }

    public function create(object $data): object
    {
        throw AppError::validation(
            'La multi-valuta non e attiva: puoi modificare solo la valuta predefinita.',
            [],
            'multi_currency_disabled',
        );
    }

    public function update(object $data): object
    {
        $payload = $this->normalizePayload($data);
        $row = $this->findDefaultCurrency($payload['id']);
        if (empty($row)) {
            throw AppError::notFound('Valuta predefinita non trovata', [], 'default_currency_not_found');
        }

        $this->ensureCodeUnique($payload['code'], (int) ($row->id ?? 0));

        $ok = $this->execPrepared(
            'UPDATE currencies SET
                code = ?,
                name = ?,
                symbol = ?,
                image = ?,
                is_default = 1,
                is_active = 1
             WHERE id = ?
               AND is_default = 1',
            [
                $payload['code'],
                $payload['name'],
                $payload['symbol'],
                $payload['image'],
                (int) $row->id,
            ],
        );
        if (!$ok) {
            throw AppError::validation('Impossibile aggiornare la valuta', [], 'currency_update_failed');
        }

        $updated = $this->findDefaultCurrency((int) $row->id);
        if (empty($updated)) {
            throw AppError::validation('Valuta aggiornata ma non leggibile', [], 'currency_update_failed');
        }

        AuditLogService::writeEvent(
            'currency.default.update',
            [
                'id' => (int) ($updated->id ?? 0),
                'code' => (string) ($updated->code ?? ''),
                'name' => (string) ($updated->name ?? ''),
            ],
            'admin',
        );

        return $updated;
    }

    public function delete(int $id): void
    {
        throw AppError::validation(
            'La valuta predefinita non puo essere eliminata in modalita core.',
            [],
            'default_currency_delete_forbidden',
        );
    }

    /**
     * @return array{id:int,code:string,name:string,symbol:?string,image:?string}
     */
    private function normalizePayload(object $data): array
    {
        $id = (int) ($data->id ?? 0);

        $code = strtoupper(trim((string) ($data->code ?? '')));
        if ($code === '' || strlen($code) > 20 || !preg_match('/^[A-Z0-9._-]+$/', $code)) {
            throw AppError::validation('Codice valuta non valido', [], 'currency_code_invalid');
        }

        $name = trim((string) ($data->name ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw AppError::validation('Nome valuta non valido', [], 'currency_name_invalid');
        }

        $symbol = trim((string) ($data->symbol ?? ''));
        if (mb_strlen($symbol) > 10) {
            throw AppError::validation('Simbolo valuta non valido', [], 'currency_symbol_invalid');
        }
        if ($symbol === '') {
            $symbol = null;
        }

        $image = trim((string) ($data->image ?? ''));
        if (mb_strlen($image) > 255) {
            throw AppError::validation('Icona valuta non valida', [], 'currency_image_invalid');
        }
        if ($image === '') {
            $image = null;
        }

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'symbol' => $symbol,
            'image' => $image,
        ];
    }

    private function findDefaultCurrency(int $id = 0): ?object
    {
        if ($id > 0) {
            $row = $this->firstPrepared(
                'SELECT id, code, name, symbol, image, is_default, is_active
                 FROM currencies
                 WHERE id = ?
                   AND is_default = 1
                 LIMIT 1',
                [$id],
            );
            if (!empty($row)) {
                return $row;
            }
        }

        $row = $this->firstPrepared(
            'SELECT id, code, name, symbol, image, is_default, is_active
             FROM currencies
             WHERE is_default = 1
             ORDER BY id ASC
             LIMIT 1',
        );

        return !empty($row) ? $row : null;
    }

    private function ensureCodeUnique(string $code, int $excludeId): void
    {
        $params = [strtoupper($code)];
        $sql = 'SELECT id
                FROM currencies
                WHERE UPPER(code) = ?';

        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $sql .= ' LIMIT 1';
        $existing = $this->firstPrepared($sql, $params);
        if (!empty($existing)) {
            throw AppError::validation('Codice valuta gia in uso', [], 'currency_code_duplicate');
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function normalizeOrder(string $raw): array
    {
        $allowed = [
            'id' => 'c.id',
            'code' => 'c.code',
            'name' => 'c.name',
            'is_active' => 'c.is_active',
        ];

        $field = 'c.name';
        $direction = 'ASC';

        $raw = trim($raw);
        if ($raw === '') {
            return [$field, $direction];
        }

        $parts = explode('|', $raw);
        $candidateField = strtolower(trim((string) $parts[0]));
        if (isset($allowed[$candidateField])) {
            $field = $allowed[$candidateField];
        }

        $candidateDirection = strtoupper(trim((string) ($parts[1] ?? 'ASC')));
        if ($candidateDirection === 'DESC') {
            $direction = 'DESC';
        }

        return [$field, $direction];
    }
}
