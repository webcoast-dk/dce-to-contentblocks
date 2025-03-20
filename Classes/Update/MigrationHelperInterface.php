<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Update;


use WEBcoast\DceToContentblocks\Utility\UpgradeUtility;

interface MigrationHelperInterface
{
    public function migrate(array &$data, array $record, string $oldFieldName, string $newFieldName, array $migrationInstruction, array $dceField, UpgradeUtility $upgradeUtility): void;
}
