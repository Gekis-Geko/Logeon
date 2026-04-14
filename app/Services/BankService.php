<?php

declare(strict_types=1);

namespace App\Services;

use Core\CurrencyLogs;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class BankService
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

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function begin(): void
    {
        $this->db->query('START TRANSACTION');
    }

    private function commit(): void
    {
        $this->db->query('COMMIT');
    }

    private function rollback(): void
    {
        try {
            $this->db->query('ROLLBACK');
        } catch (\Throwable $e) {
            // no-op
        }
    }

    private function normalizeAmount($value): int
    {
        return (int) floor((float) $value);
    }

    private function normalizeNote($value): string
    {
        $note = trim((string) $value);
        if ($note === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($note, 0, 240, 'UTF-8');
        }
        return substr($note, 0, 240);
    }

    private function getDefaultCurrency(): ?object
    {
        $row = $this->firstPrepared(
            'SELECT id, code, name, symbol, image
             FROM currencies
             WHERE is_default = 1
               AND is_active = 1
            LIMIT 1',
        );

        return !empty($row) ? $row : null;
    }

    private function requireDefaultCurrency(): object
    {
        $currency = $this->getDefaultCurrency();
        if (empty($currency) || empty($currency->id)) {
            throw AppError::validation('Valuta principale non configurata', [], 'currency_default_missing');
        }
        return $currency;
    }

    private function getCharacterRow(int $characterId, bool $forUpdate = false): ?object
    {
        if ($characterId <= 0) {
            return null;
        }

        $sql = 'SELECT id, name, surname, money, bank
                FROM characters
                WHERE id = ?
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->firstPrepared($sql, [$characterId]);
        return !empty($row) ? $row : null;
    }

    private function parseMeta($value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function sourceLabel(string $source): string
    {
        $map = [
            'bank_deposit' => 'Versamento',
            'bank_withdraw' => 'Prelievo',
            'bank_transfer_out' => 'Bonifico inviato',
            'bank_transfer_in' => 'Bonifico ricevuto',
        ];

        $key = strtolower(trim($source));
        return $map[$key] ?? 'Movimento bancario';
    }

    private function movementDescription(string $source, ?array $meta): string
    {
        $source = strtolower(trim($source));
        $meta = is_array($meta) ? $meta : [];

        if ($source === 'bank_transfer_out') {
            $targetName = isset($meta['to_character_name']) ? trim((string) $meta['to_character_name']) : '';
            if ($targetName !== '') {
                return 'Bonifico verso ' . $targetName;
            }
            return 'Bonifico inviato';
        }

        if ($source === 'bank_transfer_in') {
            $sourceName = isset($meta['from_character_name']) ? trim((string) $meta['from_character_name']) : '';
            if ($sourceName !== '') {
                return 'Bonifico ricevuto da ' . $sourceName;
            }
            return 'Bonifico ricevuto';
        }

        if ($source === 'bank_deposit') {
            return 'Versamento dal contante';
        }

        if ($source === 'bank_withdraw') {
            return 'Prelievo verso contante';
        }

        return 'Movimento bancario';
    }

    private function characterName($row): string
    {
        if (empty($row)) {
            return '';
        }
        $full = trim((string) (($row->name ?? '') . ' ' . ($row->surname ?? '')));
        return ($full !== '') ? $full : ('PG #' . (int) ($row->id ?? 0));
    }

    public function getSummary(int $characterId, int $movementsLimit = 20): array
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $limit = (int) $movementsLimit;
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $currency = $this->requireDefaultCurrency();
        $character = $this->getCharacterRow($characterId, false);
        if (empty($character)) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $movementsRaw = $this->fetchPrepared(
            'SELECT id, account, amount, balance_before, balance_after, source, meta, date_created
             FROM currency_logs
             WHERE character_id = ?
               AND currency_id = ?
               AND account = "bank"
             ORDER BY id DESC
             LIMIT ?',
            [$characterId, (int) $currency->id, $limit],
        );

        $movements = [];
        if (!empty($movementsRaw)) {
            foreach ($movementsRaw as $row) {
                $meta = $this->parseMeta($row->meta ?? null);
                $movements[] = [
                    'id' => (int) ($row->id ?? 0),
                    'amount' => (int) ($row->amount ?? 0),
                    'balance_before' => (int) ($row->balance_before ?? 0),
                    'balance_after' => (int) ($row->balance_after ?? 0),
                    'source' => (string) ($row->source ?? ''),
                    'source_label' => $this->sourceLabel((string) ($row->source ?? '')),
                    'description' => $this->movementDescription((string) ($row->source ?? ''), $meta),
                    'meta' => $meta,
                    'date_created' => (string) ($row->date_created ?? ''),
                ];
            }
        }

        return [
            'character' => [
                'id' => (int) ($character->id ?? 0),
                'name' => (string) ($character->name ?? ''),
                'surname' => (string) ($character->surname ?? ''),
                'full_name' => $this->characterName($character),
            ],
            'currency' => [
                'id' => (int) ($currency->id ?? 0),
                'code' => (string) ($currency->code ?? ''),
                'name' => (string) ($currency->name ?? ''),
                'symbol' => (string) ($currency->symbol ?? ''),
                'image' => (string) ($currency->image ?? ''),
            ],
            'balances' => [
                'cash' => (int) ($character->money ?? 0),
                'bank' => (int) ($character->bank ?? 0),
            ],
            'movements' => $movements,
        ];
    }

    public function deposit(int $characterId, $amount): array
    {
        $characterId = (int) $characterId;
        $amount = $this->normalizeAmount($amount);
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }
        if ($amount <= 0) {
            throw AppError::validation('Importo non valido', [], 'bank_amount_invalid');
        }

        $currency = $this->requireDefaultCurrency();

        $this->begin();
        try {
            $character = $this->getCharacterRow($characterId, true);
            if (empty($character)) {
                throw AppError::validation('Personaggio non valido', [], 'character_invalid');
            }

            $cashBefore = (int) ($character->money ?? 0);
            $bankBefore = (int) ($character->bank ?? 0);
            if ($cashBefore < $amount) {
                throw AppError::validation('Contanti insufficienti', [], 'bank_insufficient_cash');
            }

            $cashAfter = $cashBefore - $amount;
            $bankAfter = $bankBefore + $amount;

            $this->execPrepared(
                'UPDATE characters
                 SET money = ?,
                     bank = ?
                 WHERE id = ?
                 LIMIT 1',
                [$cashAfter, $bankAfter, $characterId],
            );

            CurrencyLogs::write(
                $characterId,
                (int) $currency->id,
                'money',
                -$amount,
                $cashBefore,
                $cashAfter,
                'bank_deposit',
                ['flow' => 'to_bank'],
            );
            CurrencyLogs::write(
                $characterId,
                (int) $currency->id,
                'bank',
                $amount,
                $bankBefore,
                $bankAfter,
                'bank_deposit',
                ['flow' => 'to_bank'],
            );

            $this->commit();

            return [
                'amount' => $amount,
                'money_before' => $cashBefore,
                'money_after' => $cashAfter,
                'bank_before' => $bankBefore,
                'bank_after' => $bankAfter,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function withdraw(int $characterId, $amount): array
    {
        $characterId = (int) $characterId;
        $amount = $this->normalizeAmount($amount);
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }
        if ($amount <= 0) {
            throw AppError::validation('Importo non valido', [], 'bank_amount_invalid');
        }

        $currency = $this->requireDefaultCurrency();

        $this->begin();
        try {
            $character = $this->getCharacterRow($characterId, true);
            if (empty($character)) {
                throw AppError::validation('Personaggio non valido', [], 'character_invalid');
            }

            $cashBefore = (int) ($character->money ?? 0);
            $bankBefore = (int) ($character->bank ?? 0);
            if ($bankBefore < $amount) {
                throw AppError::validation('Saldo banca insufficiente', [], 'bank_insufficient_funds');
            }

            $cashAfter = $cashBefore + $amount;
            $bankAfter = $bankBefore - $amount;

            $this->execPrepared(
                'UPDATE characters
                 SET money = ?,
                     bank = ?
                 WHERE id = ?
                 LIMIT 1',
                [$cashAfter, $bankAfter, $characterId],
            );

            CurrencyLogs::write(
                $characterId,
                (int) $currency->id,
                'bank',
                -$amount,
                $bankBefore,
                $bankAfter,
                'bank_withdraw',
                ['flow' => 'to_cash'],
            );
            CurrencyLogs::write(
                $characterId,
                (int) $currency->id,
                'money',
                $amount,
                $cashBefore,
                $cashAfter,
                'bank_withdraw',
                ['flow' => 'to_cash'],
            );

            $this->commit();

            return [
                'amount' => $amount,
                'money_before' => $cashBefore,
                'money_after' => $cashAfter,
                'bank_before' => $bankBefore,
                'bank_after' => $bankAfter,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function transfer(int $characterId, int $targetCharacterId, $amount, $note = ''): array
    {
        $characterId = (int) $characterId;
        $targetCharacterId = (int) $targetCharacterId;
        $amount = $this->normalizeAmount($amount);
        $note = $this->normalizeNote($note);

        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }
        if ($targetCharacterId <= 0) {
            throw AppError::validation('Destinatario non valido', [], 'bank_target_invalid');
        }
        if ($characterId === $targetCharacterId) {
            throw AppError::validation('Non puoi inviare un bonifico a te stesso', [], 'bank_target_same');
        }
        if ($amount <= 0) {
            throw AppError::validation('Importo non valido', [], 'bank_amount_invalid');
        }

        $currency = $this->requireDefaultCurrency();

        $this->begin();
        try {
            $firstId = min($characterId, $targetCharacterId);
            $secondId = max($characterId, $targetCharacterId);

            $lockedRows = $this->fetchPrepared(
                'SELECT id, name, surname, bank
                 FROM characters
                 WHERE id IN (?, ?)
                 ORDER BY id ASC
                 FOR UPDATE',
                [$firstId, $secondId],
            );

            $sender = null;
            $target = null;
            if (!empty($lockedRows)) {
                foreach ($lockedRows as $row) {
                    $id = (int) ($row->id ?? 0);
                    if ($id === $characterId) {
                        $sender = $row;
                    } elseif ($id === $targetCharacterId) {
                        $target = $row;
                    }
                }
            }

            if (empty($sender)) {
                throw AppError::validation('Personaggio non valido', [], 'character_invalid');
            }
            if (empty($target)) {
                throw AppError::validation('Destinatario non valido', [], 'bank_target_invalid');
            }

            $senderBankBefore = (int) ($sender->bank ?? 0);
            $targetBankBefore = (int) ($target->bank ?? 0);
            if ($senderBankBefore < $amount) {
                throw AppError::validation('Saldo banca insufficiente', [], 'bank_insufficient_funds');
            }

            $senderBankAfter = $senderBankBefore - $amount;
            $targetBankAfter = $targetBankBefore + $amount;
            $senderName = $this->characterName($sender);
            $targetName = $this->characterName($target);

            $this->execPrepared(
                'UPDATE characters
                 SET bank = ?
                 WHERE id = ?
                 LIMIT 1',
                [$senderBankAfter, $characterId],
            );
            $this->execPrepared(
                'UPDATE characters
                 SET bank = ?
                 WHERE id = ?
                 LIMIT 1',
                [$targetBankAfter, $targetCharacterId],
            );

            CurrencyLogs::write(
                $characterId,
                (int) $currency->id,
                'bank',
                -$amount,
                $senderBankBefore,
                $senderBankAfter,
                'bank_transfer_out',
                [
                    'to_character_id' => $targetCharacterId,
                    'to_character_name' => $targetName,
                    'note' => $note,
                ],
            );
            CurrencyLogs::write(
                $targetCharacterId,
                (int) $currency->id,
                'bank',
                $amount,
                $targetBankBefore,
                $targetBankAfter,
                'bank_transfer_in',
                [
                    'from_character_id' => $characterId,
                    'from_character_name' => $senderName,
                    'note' => $note,
                ],
            );

            $this->commit();

            return [
                'amount' => $amount,
                'sender' => [
                    'character_id' => $characterId,
                    'character_name' => $senderName,
                    'bank_before' => $senderBankBefore,
                    'bank_after' => $senderBankAfter,
                ],
                'target' => [
                    'character_id' => $targetCharacterId,
                    'character_name' => $targetName,
                    'bank_before' => $targetBankBefore,
                    'bank_after' => $targetBankAfter,
                ],
                'note' => $note,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }
}
