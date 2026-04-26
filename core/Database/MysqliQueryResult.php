<?php

declare(strict_types=1);

namespace Core\Database;

class MysqliQueryResult
{
    private ?\mysqli_result $result;

    public function __construct(?\mysqli_result $result = null)
    {
        $this->result = $result;
    }

    public function first(): object|array
    {
        if ($this->result === null || $this->result->num_rows <= 0) {
            return [];
        }

        $row = $this->result->fetch_object();
        return $row ?: [];
    }

    public function fetch(): array
    {
        if ($this->result === null || $this->result->num_rows <= 0) {
            return [];
        }

        $rows = [];
        while ($dataset = $this->result->fetch_object()) {
            $rows[] = $dataset;
        }

        return $rows;
    }

    public function count(): int
    {
        if ($this->result === null) {
            return 0;
        }

        return (int) $this->result->num_rows;
    }
}
