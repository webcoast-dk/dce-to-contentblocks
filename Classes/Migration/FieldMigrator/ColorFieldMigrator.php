<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Migration\FieldMigrator;


use WEBcoast\DceToContentblocks\Migration\FieldConfigurationMigratorInterface;

class ColorFieldMigrator implements FieldConfigurationMigratorInterface
{

    public function process(array $fieldConfiguration): array
    {
        if ($fieldConfiguration['type'] === 'text' && ($fieldConfiguration['renderType'] ?? '') === 'colorPicker') {
            $fieldConfiguration['type'] = 'color';
            unset($fieldConfiguration['renderType']);
        }

        return $fieldConfiguration;
    }

    public function getDependencies(): array
    {
        return [];
    }
}
