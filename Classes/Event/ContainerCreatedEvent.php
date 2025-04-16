<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Event;


use WEBcoast\DceToContentblocks\Utility\UpgradeUtility;

class ContainerCreatedEvent
{
    public int $containerId;
    public array $record;
    public array $dceConfiguration;
    public array $migrationInstructions;
    public UpgradeUtility $upgradeUtility;

    public function __construct(int $containerId, array $record, array $dceConfiguration, array $migrationInstructions, UpgradeUtility $upgradeUtility)
    {
        $this->containerId = $containerId;
        $this->record = $record;
        $this->dceConfiguration = $dceConfiguration;
        $this->migrationInstructions = $migrationInstructions;
        $this->upgradeUtility = $upgradeUtility;
    }

    public function getContainerId(): int
    {
        return $this->containerId;
    }

    public function getRecord(): array
    {
        return $this->record;
    }

    public function getDceConfiguration(): array
    {
        return $this->dceConfiguration;
    }

    public function getMigrationInstructions(): array
    {
        return $this->migrationInstructions;
    }

    public function getUpgradeUtility(): UpgradeUtility
    {
        return $this->upgradeUtility;
    }
}
