<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Migration\FieldMigrator;


use WEBcoast\DceToContentblocks\Migration\FieldConfigurationMigratorInterface;

class FolderFieldMigrator implements FieldConfigurationMigratorInterface
{

    public function process(array $fieldConfiguration): array
    {
        if ($fieldConfiguration['type'] === 'group' && $fieldConfiguration['internal_type'] === 'folder') {
            $fieldConfiguration['type'] = 'folder';
            unset($fieldConfiguration['internal_type']);
        }

        return $fieldConfiguration;
    }

    public function getDependencies(): array
    {
        return [];
    }
}
