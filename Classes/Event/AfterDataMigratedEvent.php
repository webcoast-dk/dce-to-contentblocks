<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Event;


use WEBcoast\DceToContentblocks\Utility\UpgradeUtility;

class AfterDataMigratedEvent
{
    public function __construct(protected array $data, protected array $record, protected array $migrationInstructions, protected UpgradeUtility $upgradeUtility) {}

    public function getData(): array
    {
        return $this->data;
    }

    public function getRecord(): array
    {
        return $this->record;
    }

    public function getMigrationInstructions(): array
    {
        return $this->migrationInstructions;
    }

    public function getUpgradeUtility(): UpgradeUtility
    {
        return $this->upgradeUtility;
    }

    public function setData(array $data): AfterDataMigratedEvent
    {
        $this->data = $data;

        return $this;
    }
}
