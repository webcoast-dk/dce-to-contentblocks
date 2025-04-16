<?php

declare(strict_types=1);

namespace WEBcoast\DceToContentblocks\Utility;

use B13\Container\Tca\ContainerConfiguration;
use B13\Container\Tca\Registry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class TcaUtility
{
    public static function addColumDefinitionsToTcaOverrides(string $file, array $fields, array $columnsOverrides, string $CType): void
    {
        if (!file_exists($file)) {
            self::createNewTcaOverrides($file);
        }

        $content = GeneralUtility::getUrl($file);
        // Find the `use` statements and check, if `TYPO3\CMS\Core\Utility\ExtensionManagementUtility` is already imported, add it if not
        if (!self::hasImport($content, ExtensionManagementUtility::class)) {
            $content = self::addImport($content, ExtensionManagementUtility::class);
        }

        $fieldsAsPhpCode = rtrim(self::arrayToPhpCode(array_filter($fields, fn ($field) => $field['config'])), "\n\r\t\v\0,");
        $newFieldListPosition = 'after';
        // Generate field list with label, if the config is empty
        $fieldList = implode(', ', array_map(function ($field, $fieldName) use (&$newFieldListPosition) {
            if ($fieldName === 'header') {
                $newFieldListPosition = 'replace';
            }
            if (!$field['config']) {
                return $fieldName . ';' . $field['label'];
            }

            return $fieldName;
        }, $fields, array_keys($fields)));

        // Generate columns overrides
        $columnsOverridesAsPhpCode = trim(self::arrayToPhpCode($columnsOverrides, 0), "\n\r\t\v\0,");

        $content .= <<<TCA

            ExtensionManagementUtility::addTCAcolumns(
                'tt_content',
                {$fieldsAsPhpCode}
            );

            ExtensionManagementUtility::addToAllTCAtypes(
                'tt_content',
                '{$fieldList}',
                '{$CType}',
                '{$newFieldListPosition}:header'
            );

            \$GLOBALS['TCA']['tt_content']['types']['{$CType}']['columnsOverrides'] = {$columnsOverridesAsPhpCode};

            TCA;

        GeneralUtility::writeFile($file, $content);

    }

    protected static function createNewTcaOverrides(string $file): void
    {
        GeneralUtility::writeFile(
            $file,
            <<<'EOF'
                <?php

                declare(strict_types=1);

                use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

                if (!defined('TYPO3')) {
                    die('Access denied.');
                }

                EOF
        );
    }

    protected static function arrayToPhpCode(array $array, int $level = 1): string
    {
        $indent = str_repeat('    ', $level);
        $nextIndent = str_repeat('    ', $level + 1);
        $code = "[\n";

        foreach ($array as $key => $value) {
            // Format the key properly (quotes for strings, no quotes for integers)
            $formattedKey = is_int($key) ? $key : "'" . addslashes($key) . "'";

            // Determine how to format the value
            if (is_array($value)) {
                $formattedValue = self::arrayToPhpCode($value, $level + 1);
            } elseif (is_string($value)) {
                $formattedValue = "'" . addslashes($value) . "'";
            } elseif (is_bool($value)) {
                $formattedValue = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $formattedValue = 'null';
            } else {
                $formattedValue = $value;
            }

            $code .= $nextIndent . $formattedKey . ' => ' . $formattedValue . (!is_array($value) ? ",\n" : '');
        }

        $code .= $indent . "],\n";

        return $code;
    }

    protected static function addImport(string $content, string $classToImport): string
    {
        // Find use statements, and add the new one
        if (preg_match('/^use\s+([a-zA-Z0-9\\\]+);/m', $content, $matches)) {
            // If there are use statements, add the new one after the first one
            $content = preg_replace(
                '/^use\s+([a-zA-Z0-9\\\]+);/m',
                'use $1;' . PHP_EOL . 'use ' . ltrim($classToImport, '\\') . ';',
                $content,
                1
            );
        } else {
            // Check, if there is a strict type declaration
            if (preg_match('/^<\?php\s*declare\(strict_types=1\);/m', $content)) {
                // If there is a strict type declaration, add the import statement after that
                $content = preg_replace(
                    '/^<\?php\s*declare\(strict_types=1\);/m',
                    '<?php' . PHP_EOL . PHP_EOL . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL . 'use ' . ltrim($classToImport, '\\') . ';',
                    $content,
                    1
                );
            } else {
                // If there is no strict type declaration, find the first `<?php` tag and add the import statement after that
                $content = preg_replace(
                    '/^<\?php/m',
                    '<?php' . PHP_EOL . PHP_EOL . 'use ' . ltrim($classToImport, '\\') . ';' . PHP_EOL,
                    $content,
                    1
                );
            }
        }

        return $content;
    }

    public static function addContainerConfigurationToTcaOverrides(string $file, string $containerCType, string $group, array $allowedChildCTypes = [], int $maxItems = 0): void
    {
        $content = GeneralUtility::getUrl($file);

        if (!self::hasImport($content, GeneralUtility::class)) {
            $content = self::addImport($content, GeneralUtility::class);
        }

        if (!self::hasImport($content, Registry::class)) {
            $content = self::addImport($content, Registry::class);
        }

        if (!self::hasImport($content, ContainerConfiguration::class)) {
            $content = self::addImport($content, ContainerConfiguration::class);
        }

        $columnConfig = [
            'name' => 'LLL:EXT:cc_config/Resources/Private/Language/locallang_db.xlf:tt_content.CType.' . $containerCType . '.content',
            'colPos' => 100,
        ];
        if (!empty($allowedChildCTypes)) {
            $columnConfig['allowed'] = $allowedChildCTypes;
        }
        if ($maxItems > 0) {
            $columnConfig['maxitems'] = $maxItems;
        }

        $columnConfigPhpCode = self::arrayToPhpCode($columnConfig, 4);

        $tca = <<<TCA

            GeneralUtility::makeInstance(Registry::class)->configureContainer(
                (new ContainerConfiguration(
                    '{$containerCType}',
                    'LLL:EXT:cc_config/Resources/Private/Language/locallang_db.xlf:tt_content.CType.{$containerCType}.title',
                    'LLL:EXT:cc_config/Resources/Private/Language/locallang_db.xlf:tt_content.CType.{$containerCType}.description',
                    [
                        [
                            {$columnConfigPhpCode}
                        ]
                    ]
                ))
                    ->setGroup('{$group}')
            );

            TCA;

        $content .= $tca;

        GeneralUtility::writeFile($file, $content);
    }

    protected static function hasImport(string $content, string $class): bool
    {
        return str_contains($content, 'use ' . ltrim($class, '\\') . ';');
    }
}
