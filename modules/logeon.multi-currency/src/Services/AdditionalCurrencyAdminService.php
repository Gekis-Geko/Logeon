<?php

declare(strict_types=1);

namespace Modules\Logeon\MultiCurrency\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class AdditionalCurrencyAdminService
{
    private DbAdapterInterface $db;

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
        $where = ['c.is_default = 0'];
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

        $whereSql = ' WHERE ' . implode(' AND ', $where);

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

        $rows = $this->fetchPrepared($listSql, $params);

        return [
            'properties' => [
                'page' => $page,
                'results_page' => $results,
                'tot' => $total,
            ],
            'dataset' => !empty($rows) ? $rows : [],
        ];
    }

    public function create(object $data): object
    {
        $payload = $this->normalizePayload($data, false);
        $this->ensureCodeUnique($payload['code'], 0);

        $ok = $this->execPrepared(
            'INSERT INTO currencies
                (code, name, symbol, image, is_default, is_active)
             VALUES
                (?, ?, ?, ?, 0, ?)',
            [
                $payload['code'],
                $payload['name'],
                $payload['symbol'],
                $payload['image'],
                $payload['is_active'],
            ],
        );

        if (!$ok) {
            throw AppError::validation('Impossibile creare la valuta', [], 'currency_create_failed');
        }

        $created = $this->firstPrepared(
            'SELECT id, code, name, symbol, image, is_default, is_active
             FROM currencies
             WHERE code = ?
             ORDER BY id DESC
             LIMIT 1',
            [$payload['code']],
        );

        if (empty($created)) {
            throw AppError::validation('Valuta creata ma non leggibile', [], 'currency_create_failed');
        }

        AuditLogService::writeEvent(
            'multi_currency.create',
            [
                'id' => (int) ($created->id ?? 0),
                'code' => (string) ($created->code ?? ''),
                'name' => (string) ($created->name ?? ''),
            ],
            'admin',
        );

        return $created;
    }

    public function update(object $data): object
    {
        $payload = $this->normalizePayload($data, true);
        $row = $this->findAdditionalCurrencyById($payload['id']);
        if (empty($row)) {
            throw AppError::notFound('Valuta non trovata', [], 'currency_not_found');
        }

        $this->ensureCodeUnique($payload['code'], $payload['id']);

        $ok = $this->execPrepared(
            'UPDATE currencies SET
                code = ?,
                name = ?,
                symbol = ?,
                image = ?,
                is_default = 0,
                is_active = ?
             WHERE id = ?
               AND is_default = 0',
            [
                $payload['code'],
                $payload['name'],
                $payload['symbol'],
                $payload['image'],
                $payload['is_active'],
                $payload['id'],
            ],
        );

        if (!$ok) {
            throw AppError::validation('Impossibile aggiornare la valuta', [], 'currency_update_failed');
        }

        $updated = $this->findAdditionalCurrencyById($payload['id']);
        if (empty($updated)) {
            throw AppError::validation('Valuta aggiornata ma non leggibile', [], 'currency_update_failed');
        }

        AuditLogService::writeEvent(
            'multi_currency.update',
            [
                'id' => (int) ($updated->id ?? 0),
                'code' => (string) ($updated->code ?? ''),
            ],
            'admin',
        );

        return $updated;
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }

        $row = $this->findAdditionalCurrencyById($id);
        if (empty($row)) {
            throw AppError::notFound('Valuta non trovata', [], 'currency_not_found');
        }

        try {
            $ok = $this->execPrepared(
                'DELETE FROM currencies
                 WHERE id = ?
                   AND is_default = 0',
                [$id],
            );
        } catch (\Throwable $e) {
            throw AppError::validation('Valuta in uso, impossibile eliminare', [], 'currency_in_use');
        }

        if (!$ok) {
            throw AppError::validation('Impossibile eliminare la valuta', [], 'currency_delete_failed');
        }

        AuditLogService::writeEvent(
            'multi_currency.delete',
            [
                'id' => $id,
                'code' => (string) ($row->code ?? ''),
            ],
            'admin',
        );
    }

    /**
     * @return array{id:int,code:string,name:string,symbol:?string,image:?string,is_active:int}
     */
    private function normalizePayload(object $data, bool $requiresId): array
    {
        $id = (int) ($data->id ?? 0);
        if ($requiresId && $id <= 0) {
            throw AppError::validation('ID non valido', [], 'id_invalid');
        }

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

        $isActive = ((int) ($data->is_active ?? 1) === 1) ? 1 : 0;

        if ((int) ($data->is_default ?? 0) === 1) {
            throw AppError::validation('Le valute aggiuntive non possono essere default', [], 'currency_default_forbidden');
        }

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'symbol' => $symbol,
            'image' => $image,
            'is_active' => $isActive,
        ];
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

    private function findAdditionalCurrencyById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT id, code, name, symbol, image, is_default, is_active
             FROM currencies
             WHERE id = ?
               AND is_default = 0
             LIMIT 1',
            [$id],
        );

        return !empty($row) ? $row : null;
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
