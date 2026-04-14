<?php

declare(strict_types=1);

namespace App\Services;

class NarrativeVisibilityService
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_STAFF_ONLY = 'staff_only';
    public const VISIBILITY_HIDDEN = 'hidden';

    /**
     * @return array<int,string>
     */
    public function allowedValues(): array
    {
        return [
            self::VISIBILITY_PUBLIC,
            self::VISIBILITY_PRIVATE,
            self::VISIBILITY_STAFF_ONLY,
            self::VISIBILITY_HIDDEN,
        ];
    }

    public function normalize($value, string $fallback = self::VISIBILITY_PUBLIC): string
    {
        $normalized = strtolower(trim((string) $value));
        if (!in_array($normalized, $this->allowedValues(), true)) {
            return $fallback;
        }

        return $normalized;
    }

    public function canView($visibility, bool $isStaff = false, bool $isOwner = false): bool
    {
        $visibility = $this->normalize($visibility);
        if ($isStaff) {
            return true;
        }

        if ($visibility === self::VISIBILITY_PUBLIC) {
            return true;
        }

        if ($visibility === self::VISIBILITY_PRIVATE) {
            return $isOwner;
        }

        return false;
    }

    /**
     * @param array<int,mixed> $rows
     * @param callable $visibilityResolver fn($row):string
     * @return array<int,mixed>
     */
    public function filterRows(array $rows, callable $visibilityResolver, bool $isStaff = false, bool $isOwner = false): array
    {
        if ($isStaff) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $visibility = $visibilityResolver($row);
            if ($this->canView($visibility, $isStaff, $isOwner)) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }
}
