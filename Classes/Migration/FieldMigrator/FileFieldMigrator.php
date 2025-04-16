<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Migration\FieldMigrator;


use WEBcoast\DceToContentblocks\Migration\FieldConfigurationMigratorInterface;

class FileFieldMigrator implements FieldConfigurationMigratorInterface
{

    public function process(array $fieldConfiguration): array
    {
        if (
            ($fieldConfiguration['type'] === 'group' && $fieldConfiguration['internal_type'] === 'file')
            || ($fieldConfiguration['type'] === 'group' && $fieldConfiguration['internal_type'] === 'db' && ($fieldConfiguration['appearance']['elementBrowserType'] ?? '') === 'file')
            || ($fieldConfiguration['type'] === 'inline' && $fieldConfiguration['foreign_table'] === 'sys_file_reference')
        ) {
            $fieldConfiguration['type'] = 'file';
            unset($fieldConfiguration['internal_type']);

            if ($fieldConfiguration['appearance']['elementBrowserAllowed'] ?? null) {
                $fieldConfiguration['allowed'] = $fieldConfiguration['appearance']['elementBrowserAllowed'];
                unset($fieldConfiguration['appearance']['elementBrowserAllowed'], $fieldConfiguration['appearance']['elementBrowserType']);
            } elseif ($fieldConfiguration['overrideChildTca']['columns']['uid_local']['config']['appearance']['elementBrowserAllowed'] ?? null) {
                $fieldConfiguration['allowed'] = $fieldConfiguration['overrideChildTca']['columns']['uid_local']['config']['appearance']['elementBrowserAllowed'];
                unset($fieldConfiguration['overrideChildTca']['columns']['uid_local']['config']['appearance']['elementBrowserType'], $fieldConfiguration['overrideChildTca']['columns']['uid_local']['config']['appearance']['elementBrowserAllowed']);
                if (empty($fieldConfiguration['overrideChildTca']['columns']['uid_local']['config']['appearance'])) {
                    unset($fieldConfiguration['overrideChildTca']['columns']['uid_local']['config']['appearance']);
                }
                if (empty($fieldConfiguration['overrideChildTca']['columns']['uid_local']['config'])) {
                    unset($fieldConfiguration['overrideChildTca']['columns']['uid_local']['config']);
                }
                if (empty($fieldConfiguration['overrideChildTca']['columns']['uid_local'])) {
                    unset($fieldConfiguration['overrideChildTca']['columns']['uid_local']);
                }
                if (empty($fieldConfiguration['overrideChildTca']['columns'])) {
                    unset($fieldConfiguration['overrideChildTca']['columns']);
                }
                if (empty($fieldConfiguration['overrideChildTca'])) {
                    unset($fieldConfiguration['overrideChildTca']);
                }
            } elseif ($fieldConfiguration['foreign_selector_fieldTcaOverride']['config']['appearance']['elementBrowserAllowed'] ?? null) {
                $fieldConfiguration['allowed'] = $fieldConfiguration['foreign_selector_fieldTcaOverride']['config']['appearance']['elementBrowserAllowed'];
                unset($fieldConfiguration['foreign_selector_fieldTcaOverride']['config']['appearance']['elementBrowserType'], $fieldConfiguration['foreign_selector_fieldTcaOverride']['config']['appearance']['elementBrowserAllowed']);
                if (empty($fieldConfiguration['foreign_selector_fieldTcaOverride']['config']['appearance'])) {
                    unset($fieldConfiguration['foreign_selector_fieldTcaOverride']['config']['appearance']);
                }
                if (empty($fieldConfiguration['foreign_selector_fieldTcaOverride']['config'])) {
                    unset($fieldConfiguration['foreign_selector_fieldTcaOverride']['config']);
                }
                if (empty($fieldConfiguration['foreign_selector_fieldTcaOverride'])) {
                    unset($fieldConfiguration['foreign_selector_fieldTcaOverride']);
                }
            }
            if ($fieldConfiguration['foreign_types'] ?? null) {
                $fieldConfiguration['overrideChildTca']['types'] = $fieldConfiguration['foreign_types'];
                unset($fieldConfiguration['foreign_types']);
            }
            if ((string) ($fieldConfiguration['appearance']['useSortable'] ?? 0) === '1') {
                unset($fieldConfiguration['appearance']['useSortable']);
            }

            if (empty($fieldConfiguration['appearance'])) {
                unset($fieldConfiguration['appearance']);
            }

            unset(
                $fieldConfiguration['upload_folder'],
                $fieldConfiguration['size'],
                $fieldConfiguration['show_thumbs'],
                $fieldConfiguration['foreign_table'],
                $fieldConfiguration['foreign_field'],
                $fieldConfiguration['foreign_sortby'],
                $fieldConfiguration['foreign_table_field'],
                $fieldConfiguration['foreign_match_fields'],
                $fieldConfiguration['foreign_label'],
                $fieldConfiguration['foreign_selector']
            );
        }

        return $fieldConfiguration;
    }

    public function getDependencies(): array
    {
        return [];
    }
}
