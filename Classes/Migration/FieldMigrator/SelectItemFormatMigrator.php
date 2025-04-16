<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Migration\FieldMigrator;


use WEBcoast\DceToContentblocks\Migration\FieldConfigurationMigratorInterface;

class SelectItemFormatMigrator implements FieldConfigurationMigratorInterface
{

    public function process(array $fieldConfiguration): array
    {
        if ($fieldConfiguration['type'] === 'select' && $fieldConfiguration['items'] ?? null) {
            foreach ($fieldConfiguration['items'] as &$item) {
                if (is_array($item)) {
                    $item['label'] = $item[0];
                    $item['value'] = $item[1];
                    unset($item[0], $item[1]);

                    if ($item[2] ?? null) {
                        $item['icon'] = $item[2];
                        unset($item[2]);
                    }

                    if ($item[3] ?? null) {
                        $item['group'] = $item[3];
                        unset($item[3]);
                    }

                    if ($item[4] ?? null) {
                        $item['description'] = $item[4];
                        unset($item[4]);
                    }
                }
            }
        }

        return $fieldConfiguration;
    }

    public function getDependencies(): array
    {
        return [];
    }
}
