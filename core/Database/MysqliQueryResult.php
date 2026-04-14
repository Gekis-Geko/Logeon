<?php

declare(strict_types=1);

namespace Core\Database;

class MysqliQueryResult
{
    /** @var \mysqli_result|null */
    private $result;

    public function __construct($result = null)
    {
        $this->result = ($result instanceof \mysqli_result) ? $result : null;
    }

    public function first()
    {
        if (!$this->result || !isset($this->result->num_rows)) {
            return [];
        }

        if ($this->result->num_rows <= 0) {
            return [];
        }

        $row = $this->result->fetch_object();
        return $row ?: [];
    }

    public function fetch(): array
    {
        if (!$this->result || !isset($this->result->num_rows)) {
            return [];
        }

        if ($this->result->num_rows <= 0) {
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
        if (!$this->result || !isset($this->result->num_rows)) {
            return 0;
        }

        return (int) $this->result->num_rows;
    }
}
