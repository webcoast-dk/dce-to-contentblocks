<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Migration\FieldMigrator;


use WEBcoast\DceToContentblocks\Migration\FieldConfigurationMigratorInterface;

class LinkFieldMigrator implements FieldConfigurationMigratorInterface
{

    public function process(array $fieldConfiguration): array
    {
        if ($fieldConfiguration['type'] === 'text' && ($fieldConfiguration['renderType'] ?? '') === 'inputLink') {
            $fieldConfiguration['type'] = 'link';
            unset($fieldConfiguration['renderType']);
        }

        return $fieldConfiguration;
    }

    public function getDependencies(): array
    {
        return [];
    }
}
