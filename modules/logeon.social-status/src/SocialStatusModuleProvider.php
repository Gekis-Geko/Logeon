<?php

declare(strict_types=1);

namespace Modules\Logeon\SocialStatus;

use App\Contracts\SocialStatusProviderInterface;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class SocialStatusModuleProvider implements SocialStatusProviderInterface
{
    private DbAdapterInterface $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    public function syncForCharacter(int $characterId, float $fame, ?int $currentStatusId): ?object
    {
        if ($characterId <= 0) {
            return null;
        }

        $status = $this->resolveStatusByFame($fame);
        if (empty($status)) {
            if ($currentStatusId !== null && $currentStatusId > 0) {
                $this->db->executePrepared(
                    'UPDATE characters SET socialstatus_id = NULL WHERE id = ?',
                    [$characterId],
                );
            }
            return null;
        }

        $resolvedStatusId = (int) ($status->id ?? 0);
        if ($resolvedStatusId > 0 && $resolvedStatusId !== (int) ($currentStatusId ?? 0)) {
            $this->db->executePrepared(
                'UPDATE characters SET socialstatus_id = ? WHERE id = ?',
                [$resolvedStatusId, $characterId],
            );
        }

        return $status;
    }

    public function meetsRequirement(int $characterId, ?int $requiredStatusId): bool
    {
        if ($requiredStatusId === null || $requiredStatusId <= 0) {
            return true;
        }
        if ($characterId <= 0) {
            return false;
        }

        $row = $this->db->fetchOnePrepared(
            'SELECT socialstatus_id
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );
        if (empty($row)) {
            return false;
        }

        return (int) ($row->socialstatus_id ?? 0) === (int) $requiredStatusId;
    }

    /**
     * @return array<int,object>
     */
    public function listAll(): array
    {
        $rows = $this->db->fetchAllPrepared(
            'SELECT id, name
             FROM social_status
             ORDER BY name ASC, id ASC',
        );

        return !empty($rows) ? $rows : [];
    }

    public function getShopDiscount(int $characterId): float
    {
        if ($characterId <= 0) {
            return 0.0;
        }

        $row = $this->db->fetchOnePrepared(
            'SELECT ss.shop_discount
             FROM characters c
             LEFT JOIN social_status ss ON c.socialstatus_id = ss.id
             WHERE c.id = ?
             LIMIT 1',
            [$characterId],
        );

        $discount = (!empty($row) && isset($row->shop_discount)) ? (float) $row->shop_discount : 0.0;
        if ($discount < 0.0) {
            return 0.0;
        }

        return $discount;
    }

    public function getById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->db->fetchOnePrepared(
            'SELECT id, name, description, icon, min, max, shop_discount, unlock_home, quest_tier
             FROM social_status
             WHERE id = ?
             LIMIT 1',
            [$id],
        );

        return !empty($row) ? $row : null;
    }

    private function resolveStatusByFame(float $fame): ?object
    {
        $row = $this->db->fetchOnePrepared(
            'SELECT id, name, description, icon, min, max, shop_discount, unlock_home, quest_tier
             FROM social_status
             WHERE ? >= min AND ? <= max
             ORDER BY min DESC
             LIMIT 1',
            [$fame, $fame],
        );

        return !empty($row) ? $row : null;
    }
}

