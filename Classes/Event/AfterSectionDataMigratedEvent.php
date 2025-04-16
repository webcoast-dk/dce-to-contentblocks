<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Event;


use WEBcoast\DceToContentblocks\Utility\UpgradeUtility;

class AfterSectionDataMigratedEvent
{
    public function __construct(
        protected array $data,
        protected array $record,
        protected array $section,
        protected string $sectionId,
        protected string $table,
        protected string $oldFieldName,
        protected string $newFieldName,
        protected array $migrationInstructions,
        protected UpgradeUtility $upgradeUtility
    ) {}

    public function getData(): array
    {
        return $this->data;
    }

    public function getRecord(): array
    {
        return $this->record;
    }

    public function getSection(): array
    {
        return $this->section;
    }

    public function getSectionId(): string
    {
        return $this->sectionId;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getOldFieldName(): string
    {
        return $this->oldFieldName;
    }

    public function getNewFieldName(): string
    {
        return $this->newFieldName;
    }

    public function getMigrationInstructions(): array
    {
        return $this->migrationInstructions;
    }

    public function getUpgradeUtility(): UpgradeUtility
    {
        return $this->upgradeUtility;
    }

    public function setData(array $data): AfterSectionDataMigratedEvent
    {
        $this->data = $data;

        return $this;
    }
}
