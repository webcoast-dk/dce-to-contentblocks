<?php

declare(strict_types=1);

namespace WEBcoast\DceToContentblocks\Update;

readonly class RecordDataMigratorCollection
{
    public function __construct(protected array $mapping) {}

    public function getAll()
    {
        return $this->mapping;
    }

    public function get(string $contentType): ?string
    {
        return $this->mapping[$contentType] ?? null;
    }
}
