<?php

declare(strict_types=1);

namespace WEBcoast\DceToContentblocks\Migration\FieldMigrator;

use WEBcoast\DceToContentblocks\Migration\FieldConfigurationMigratorInterface;

class MissingSelectRenderType implements FieldConfigurationMigratorInterface
{
    public function process(array $fieldConfiguration): array
    {
        if ($fieldConfiguration['type'] === 'select' && !($fieldConfiguration['renderType'] ?? '')) {
            $fieldConfiguration['renderType'] = 'selectSingle';
        }

        return $fieldConfiguration;
    }

    public function getDependencies(): array
    {
        return [];
    }
}
