<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Migration\FieldMigrator;


use WEBcoast\DceToContentblocks\Migration\FieldConfigurationMigratorInterface;

class RemoveDceOptionsMigrator implements FieldConfigurationMigratorInterface
{

    public function process(array $fieldConfiguration): array
    {
        return array_filter($fieldConfiguration, function ($key) {
            return !str_starts_with($key, 'dce_');
        }, ARRAY_FILTER_USE_KEY);
    }

    public function getDependencies(): array
    {
        return [];
    }
}
