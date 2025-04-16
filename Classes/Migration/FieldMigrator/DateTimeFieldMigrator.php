<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Migration\FieldMigrator;


use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WEBcoast\DceToContentblocks\Migration\FieldConfigurationMigratorInterface;

class DateTimeFieldMigrator implements FieldConfigurationMigratorInterface
{

    public function process(array $fieldConfiguration): array
    {
        if ($fieldConfiguration['type'] === 'text' && ($fieldConfiguration['renderType'] ?? '') === 'inputDateTime') {
            $fieldConfiguration['type'] = 'datetime';
            unset($fieldConfiguration['renderType']);
            $eval = GeneralUtility::trimExplode(',', $fieldConfiguration['eval'] ?? '');
            if (in_array('date', $eval)) {
                $fieldConfiguration['format'] = 'date';
                ArrayUtility::removeArrayEntryByValue($eval, 'date');
            } elseif (in_array('datetime', $eval)) {
                $fieldConfiguration['format'] = 'datetime';
                ArrayUtility::removeArrayEntryByValue($eval, 'datetime');
            } elseif (in_array('time', $eval)) {
                $fieldConfiguration['format'] = 'time';
                ArrayUtility::removeArrayEntryByValue($eval, 'time');
            } elseif (in_array('timesec', $eval)) {
                $fieldConfiguration['format'] = 'timesec';
                ArrayUtility::removeArrayEntryByValue($eval, 'timesec');
            }
            $fieldConfiguration['eval'] = implode(',', $eval);
        }

        return $fieldConfiguration;
    }

    public function getDependencies(): array
    {
        return [];
    }
}
