<?php

declare(strict_types=1);

namespace WEBcoast\DceToContentblocks\Utility;

use TYPO3\CMS\ContentBlocks\Builder\ContentBlockBuilder;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentTypeIcon;
use TYPO3\CMS\ContentBlocks\Definition\Factory\UniqueIdentifierCreator;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;
use TYPO3\CMS\ContentBlocks\Utility\ContentBlockPathUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Package\Package;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WEBcoast\DceToContentblocks\Exception\SkipFieldException;
use WEBcoast\DceToContentblocks\Repository\DceRepository;

readonly class MigrationUtility
{
    public function __construct(
        protected DceRepository $dceRepository,
        protected ContentBlockRegistry $contentBlockRegistry,
        protected ContentBlockBuilder $contentBlockBuilder
    ) {}

    public function createContentBlockConfiguration(int|string $dceIdentifier, array $migrationInstructions): void
    {
        /** @var Package $package */
        $package = $migrationInstructions['package'];
        $vendor = $migrationInstructions['vendor'];
        $identifier = $migrationInstructions['identifier'];

        $dceConfiguration = $this->dceRepository->getConfiguration($dceIdentifier);

        if (!$this->contentBlockRegistry->hasContentBlock($vendor . '/' . $identifier)) {
            $contentBlock = $this->buildContentBlock($vendor, $identifier, $migrationInstructions, $dceConfiguration, $package);
            $this->contentBlockBuilder->create($contentBlock);
            $this->copyTemplate($dceConfiguration, $contentBlock);
            $this->copyIcon($dceConfiguration, $contentBlock);
        }
    }

    private function buildContentBlock(string $vendor, string $name, array $migrationInstructions, array $dceConfiguration, Package $package): LoadedContentBlock
    {
        $fullName = $vendor . '/' . $name;
        $description = 'Description for ' . ContentType::CONTENT_ELEMENT->getHumanReadable() . ' ' . $fullName;
        $configuration = [
            'table' => 'tt_content',
            'typeField' => 'CType',
            'name' => $fullName,
            'typeName' => UniqueIdentifierCreator::createContentTypeIdentifier($fullName),
            'title' => $dceConfiguration['title'],
            'description' => $dceConfiguration['wizard_description'] ?: $description,
            'group' => $dceConfiguration['wizard_category'] ?: 'default',
            'prefixFields' => $migrationInstructions['prefixFields'] ?? true,
            'prefixType' => $migrationInstructions['prefixType'] ?? 'full',
        ];

        $configuration['fields'] = $this->buildFieldsConfiguration($migrationInstructions, $dceConfiguration, $package);

        if (count(array_intersect(['space_before_class', 'space_after_class', 'layout', 'frame_class'], GeneralUtility::trimExplode(',', $dceConfiguration['palette_fields'])))) {
            $configuration['basics'][] = 'TYPO3/Appearance';
        }

        if ($dceConfiguration['show_category_tab'] ?? false) {
            $configuration['fields'][] = 'TYPO3/Categories';
        }

        return new LoadedContentBlock(
            $vendor . '/' . $name,
            $configuration,
            new ContentTypeIcon(),
            $package->getValueFromComposerManifest('extra')?->{'typo3/cms'}?->{'extension-key'} ?? '',
            'EXT:' . ($package->getValueFromComposerManifest('extra')?->{'typo3/cms'}?->{'extension-key'} ?? '') . '/' . ContentBlockPathUtility::getRelativeContentElementsPath(),
            ContentType::CONTENT_ELEMENT
        );
    }

    private function buildFieldsConfiguration(array $migrationInstructions, array $dceConfiguration, Package $package): array
    {
        if (array_key_exists('identifier', $dceConfiguration)) {
            $dceFields = $this->dceRepository->fetchFieldsByParentDce($dceConfiguration['uid']);
        } else {
            $dceFields = $this->dceRepository->fetchFieldsByParentField($dceConfiguration['uid']);
        }

        $fields = [];
        foreach ($dceFields as $dceField) {
            try {
                $fields[] = $this->buildFieldConfiguration($migrationInstructions, $dceField, $package);
            } catch (SkipFieldException $e) {
            }
        }

        return $fields;
    }

    protected function buildFieldConfiguration(array $migrationInstructions, array $dceField, Package $package): array
    {
        $fieldMigrationConfig = $migrationInstructions['fields'][$dceField['variable']] ?? [];
        $fieldConfiguration = [
            'identifier' => $dceField['map_to'] ?: ($fieldMigrationConfig['fieldName'] ?? $dceField['variable']),
            'label' => $dceField['title']
        ];

        if ($dceField['map_to'] || ($fieldMigrationConfig['useExistingField'] ?? false)) {
            $fieldConfiguration['useExistingField'] = true;
        }

        if (
            ($fieldMigrationConfig['mergeWith'] ?? false)
            || ($fieldMigrationConfig['skip'] ?? false)
        ) {
            throw new SkipFieldException('Field should be moved or merged with another field');
        }

        if ((int) $dceField['type'] === 0) {
            $config = GeneralUtility::xml2array($dceField['configuration']) ?? [];

            $fieldConfiguration['type'] = match ($config['type']) {
                'category' => 'Category',
                'check' => 'Checkbox',
                'color' => 'Color',
                'datetime' => 'DateTime',
                'email' => 'Email',
                'file' => 'File',
                'flex' => 'FlexForm',
                'folder' => 'Folder',
                'group' => 'Relation',
                'imageManipulation' => 'ImageManipulation',
                'inline' => 'Collection',
                'input' => 'Text',
                'json' => 'Json',
                'language' => 'Language',
                'link' => 'Link',
                'none' => 'None',
                'number' => 'Number',
                'passthrough' => 'Pass',
                'password' => 'Password',
                'radio' => 'Radio',
                'select' => 'Select',
                'slug' => 'Slug',
                'text' => 'Textarea',
                'uuid' => 'Uuid',
            };

            unset($config['type']);

            if ($fieldConfiguration['type'] === 'Relation' && $config['internal_type'] === 'file') {
                $fieldConfiguration['type'] = 'File';
                $fieldConfiguration['extendedPalette'] = true;
                unset($config['internal_type'], $config['uploadfolder']);
            } elseif ($fieldConfiguration['type'] === 'Relation' && $config['internal_type'] === 'folder') {
                $fieldConfiguration['type'] = 'Folder';
                unset($config['internal_type']);
            } elseif ($fieldConfiguration['type'] === 'Relation' && $config['internal_type'] === 'db') {
                unset($config['internal_type']);
                if ($config['appearance']['elementBrowserType'] ?? '' === 'file') {
                    $fieldConfiguration['type'] = 'File';
                    unset($config['allowed'], $config['appearance']['elementBrowserType']);
                    if ($config['appearance']['elementBrowserAllowed'] ?? null) {
                        $fieldConfiguration['allowed'] = $config['appearance']['elementBrowserAllowed'];
                        unset($config['appearance']['elementBrowserAllowed']);
                    }
                    if (empty($config['appearance'])) {
                        unset($config['appearance']);
                    }
                }
            } elseif ($fieldConfiguration['type'] === 'Collection' && $config['foreign_table'] === 'sys_file_reference') {
                $fieldConfiguration['type'] = 'File';
                if ($config['overrideChildTca']['columns']['uid_local']['config']['appearance']['elementBrowserAllowed'] ?? null) {
                    $fieldConfiguration['allowed'] = $config['overrideChildTca']['columns']['uid_local']['config']['appearance']['elementBrowserAllowed'];
                    unset($config['overrideChildTca']['columns']['uid_local']['config']['appearance']['elementBrowserType'], $config['overrideChildTca']['columns']['uid_local']['config']['appearance']['elementBrowserAllowed']);
                    if (empty($config['overrideChildTca']['columns']['uid_local']['config']['appearance'])) {
                        unset($config['overrideChildTca']['columns']['uid_local']['config']['appearance']);
                    }
                    if (empty($config['overrideChildTca']['columns']['uid_local']['config'])) {
                        unset($config['overrideChildTca']['columns']['uid_local']['config']);
                    }
                    if (empty($config['overrideChildTca']['columns']['uid_local'])) {
                        unset($config['overrideChildTca']['columns']['uid_local']);
                    }
                    if (empty($config['overrideChildTca']['columns'])) {
                        unset($config['overrideChildTca']['columns']);
                    }
                    if (empty($config['overrideChildTca'])) {
                        unset($config['overrideChildTca']);
                    }
                } elseif ($config['foreign_selector_fieldTcaOverride']['config']['appearance']['elementBrowserAllowed'] ?? null) {
                    $fieldConfiguration['allowed'] = $config['foreign_selector_fieldTcaOverride']['config']['appearance']['elementBrowserAllowed'];
                    unset($config['foreign_selector_fieldTcaOverride']['config']['appearance']['elementBrowserType'], $config['foreign_selector_fieldTcaOverride']['config']['appearance']['elementBrowserAllowed']);
                    if (empty($config['foreign_selector_fieldTcaOverride']['config']['appearance'])) {
                        unset($config['foreign_selector_fieldTcaOverride']['config']['appearance']);
                    }
                    if (empty($config['foreign_selector_fieldTcaOverride']['config'])) {
                        unset($config['foreign_selector_fieldTcaOverride']['config']);
                    }
                    if (empty($config['foreign_selector_fieldTcaOverride'])) {
                        unset($config['foreign_selector_fieldTcaOverride']);
                    }
                }
                if ($config['foreign_types'] ?? null) {
                    $fieldConfiguration['overrideChildTca']['types'] = $config['foreign_types'];
                    unset($config['foreign_types']);
                }
                if ((string) ($config['appearance']['useSortable'] ?? 0) === '1') {
                    unset($config['appearance']['useSortable']);
                }
                unset($config['foreign_table'], $config['foreign_field'], $config['foreign_sortby'], $config['foreign_table_field'], $config['foreign_match_fields'], $config['foreign_label'], $config['foreign_selector']);
            } elseif ($fieldConfiguration['type'] === 'Select' && !($config['renderType'] ?? '')) {
                $fieldConfiguration['renderType'] = 'selectSingle';
            } elseif ($fieldConfiguration['type'] === 'Text' && ($config['renderType'] ?? '') === 'inputLink') {
                $fieldConfiguration['type'] = 'Link';
                unset($config['renderType']);
            } elseif ($fieldConfiguration['type'] === 'Text' && ($config['renderType'] ?? '') === 'inputDateTime') {
                $fieldConfiguration['type'] = 'DateTime';
                unset($config['renderType']);
                $eval = GeneralUtility::trimExplode(',', $config['eval'] ?? '');
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
                $config['eval'] = implode(',', $eval);
            } elseif ($fieldConfiguration['type'] === 'Text' && ($config['renderType'] ?? '') === 'colorPicker') {
                $fieldConfiguration['type'] = 'Color';
                unset($config['renderType']);
            }

            // Convert numbered select items to associative array with 0 => label and 1 => value
            if ($fieldConfiguration['type'] === 'Select' && $config['items'] ?? null) {
                foreach ($config['items'] as &$item) {
                    if (is_array($item)) {
                        $item['label'] = $item[0];
                        $item['value'] = $item[1];
                        unset($item[0], $item[1]);
                    }
                }
            }

            // Extract `required` from `eval` into field configuration
            if ($config['eval'] ?? '') {
                $eval = GeneralUtility::trimExplode(',', $config['eval']);
                if (in_array('required', $eval)) {
                    $fieldConfiguration['required'] = true;
                    $eval = ArrayUtility::removeArrayEntryByValue($eval, 'required');
                }
                $config['eval'] = implode(',', $eval);
            }

            $config = array_filter($config, function ($key) {
                return !str_starts_with($key, 'dce_');
            }, ARRAY_FILTER_USE_KEY);

            $fieldConfiguration = array_replace_recursive($fieldConfiguration, $config);
        } elseif ((int) $dceField['type'] === 1) {
            $fieldConfiguration['type'] = 'Tab';
        } elseif ((int) $dceField['type'] === 2) {
            if (!($fieldConfiguration['useExistingField'] ?? false)) {
                if ($fieldMigrationConfig['traverse'] ?? false) {
                    $childFields = $this->buildFieldsConfiguration($fieldMigrationConfig, $dceField, $package);
                    $newFieldName = $fieldMigrationConfig['fieldName'] ?? $dceField['variable'];
                    $childFieldConfiguration = array_filter($childFields, function ($childField) use ($newFieldName) {
                        return $childField['identifier'] === $newFieldName;
                    })[0] ?? null;

                    if (!$childFieldConfiguration) {
                        throw new \RuntimeException(sprintf('Could not determine section field to use for traversing. Please check your migration instructions for field "%s".', $dceField['variable']), 1676581234);
                    }

                    $fieldConfiguration = array_replace_recursive($childFieldConfiguration, $fieldConfiguration);
                } else {
                    // Section type
                    $extensionName = str_replace('_', '', $package->getValueFromComposerManifest('extra')?->{'typo3/cms'}?->{'extension-key'} ?? '');
                    $fieldConfiguration = array_replace_recursive(
                        $fieldConfiguration,
                        [
                            'type' => 'Collection',
                            'table' => $fieldMigrationConfig['table'] ?? 'tx_' . $extensionName . '_domain_model_' . $fieldConfiguration['identifier'],
                        ]
                    );

                    if ($fieldMigrationConfig['foreign_field'] ?? null) {
                        $fieldConfiguration['foreign_field'] = $fieldMigrationConfig['foreign_field'];
                    }


                }
            }
        }

        if ($fieldConfiguration['useExistingField'] ?? false) {
            unset($fieldConfiguration['type']);
        }

        return $fieldConfiguration;
    }

    protected function copyTemplate(array $dceConfiguration, LoadedContentBlock $contentBlock): void
    {
        $templateContent = '';
        if ($dceConfiguration['template_type'] === 'inline') {
            $templateContent = $dceConfiguration['template_content'] ?? '';
        } elseif ($dceConfiguration['template_type'] === 'file') {
            $templatePath = $dceConfiguration['template_file'] ?? '';
            if (str_starts_with($templatePath, 'EXT:')) {
                $templateContent = file_get_contents(GeneralUtility::getFileAbsFileName($templatePath));
            } elseif (str_starts_with($templatePath, 't3://file')) {
                $fileUid = (int) substr($templatePath, 14);

                /** @var FileRepository $fileRepository */
                $fileRepository = GeneralUtility::makeInstance(FileRepository::class);
                $file = $fileRepository->findByUid($fileUid);
                $templateContent = $file->getContents();
            } elseif (file_exists(Environment::getPublicPath() . '/' . $templatePath)) {
                $templateContent = file_get_contents(Environment::getPublicPath() . '/' . $templatePath);
            }
        }

        if ($templateContent) {
            GeneralUtility::writeFile(GeneralUtility::getFileAbsFileName($contentBlock->getExtPath() . '/' . $contentBlock->getPackage() . '/' . ContentBlockPathUtility::getFrontendTemplatePath()), $templateContent);
        }
    }

    protected function copyIcon(array $dceConfiguration, LoadedContentBlock $contentBlock): void
    {
        $iconContent = null;
        $iconFileExt = null;
        if ($dceConfiguration['wizard_icon']) {
            /** @var IconRegistry $iconRegistry */
            $iconRegistry = GeneralUtility::makeInstance(IconRegistry::class);
            $iconConfiguration = $iconRegistry->getIconConfigurationByIdentifier($dceConfiguration['wizard_icon']);
            $absoluteIconPath = GeneralUtility::getFileAbsFileName($iconConfiguration['options']['source']);
            $iconContent = file_get_contents($absoluteIconPath);
            $iconFileExt = pathinfo($absoluteIconPath, PATHINFO_EXTENSION);
        }

        if ($iconContent && $iconFileExt) {
            GeneralUtility::writeFile(GeneralUtility::getFileAbsFileName($contentBlock->getExtPath() . '/' . $contentBlock->getPackage() . '/' . ContentBlockPathUtility::getIconPathWithoutFileExtension() . '.' . $iconFileExt), $iconContent);
        }
    }
}
