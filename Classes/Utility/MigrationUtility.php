<?php

declare(strict_types=1);

namespace WEBcoast\DceToContentblocks\Utility;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WEBcoast\DceToContentblocks\Exception\SkipFieldException;
use WEBcoast\DceToContentblocks\Repository\DceRepository;

readonly class MigrationUtility
{
    public function __construct(
        protected DceRepository $dceRepository,
        protected ContentBlockRegistry $contentBlockRegistry,
        protected ContentBlockBuilder $contentBlockBuilder,
        #[Autowire(service: 'webcoast.dce_to_contentblocks.ordered_migrators')]
        protected array $fieldConfigurationMigrators
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

        if ($dceConfiguration['enable_container'] ?? false) {
            $containerFields = $this->buildFieldsConfiguration($migrationInstructions['container']['fields'], $dceConfiguration, $package, false, true);
            $labels = [];
            $columnsOverrides = [];
            $fieldIdentifiers = array_column($containerFields, 'identifier');
            $containerFields = array_map(function ($field, $fieldName) use ($package, &$labels, &$columnsOverrides, $configuration) {
                unset($field['identifier']);
                $labelKey = 'tt_content.' . $fieldName . '.label';
                if ($field['useExistingField'] ?? false) {
                    $labelKey = 'tt_content.' . $fieldName . '.types.' . $configuration['typeName'] . '.label';
                    $labels[$labelKey] = $field['label'];
                    unset($field['useExistingField'], $field['label']);
                    $columnsOverrides[$fieldName]['config'] = $field;
                    $field = null;
                } else {
                    $labels[$labelKey] = $field['label'];
                    unset($field['label']);
                }

                return [
                    'label' => 'LLL:EXT:' . $package->getValueFromComposerManifest('extra')->{'typo3/cms'}->{'extension-key'} . '/Resources/Private/Language/locallang_db.xlf:' . $labelKey,
                    'config' => $field
                ];
            }, $containerFields, array_column($containerFields, 'identifier'));
            $containerFields = array_combine($fieldIdentifiers, $containerFields);

            $containerCType = UniqueIdentifierCreator::createContentTypeIdentifier($fullName) . '_container';
            $tcaOverridesFile = GeneralUtility::getFileAbsFileName('EXT:' . $package->getValueFromComposerManifest('extra')->{'typo3/cms'}->{'extension-key'} . '/Configuration/TCA/Overrides/tt_content.php');
            TcaUtility::addContainerConfigurationToTcaOverrides($tcaOverridesFile, $containerCType, $dceConfiguration['wizard_category'] ?? 'container', ['CType' => UniqueIdentifierCreator::createContentTypeIdentifier($fullName)], $dceConfiguration['container_item_limit'] ?? 0);
            TcaUtility::addColumDefinitionsToTcaOverrides($tcaOverridesFile, $containerFields, $columnsOverrides, $containerCType);
            $xliffFile = GeneralUtility::getFileAbsFileName('EXT:' . $package->getValueFromComposerManifest('extra')->{'typo3/cms'}->{'extension-key'} . '/Resources/Private/Language/locallang_db.xlf');
            $labels['tt_content.CType.' . $containerCType . '.label'] = $configuration['title'] . ' Container';
            $labels['tt_content.CType.' . $containerCType . '.description'] = 'Description for ' . $configuration['title'] . ' Container';
            $labels['tt_content.CType.' . $containerCType . '.content'] = 'Content';
            XliffUtility::addLabelsToFile($xliffFile, $labels, $package->getValueFromComposerManifest('extra')->{'typo3/cms'}->{'extension-key'});
        }

        $configuration['fields'] = $this->buildFieldsConfiguration($migrationInstructions['fields'], $dceConfiguration, $package);

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

    private function buildFieldsConfiguration(array $migrationInstructions, array $dceConfiguration, Package $package, bool $convertType = true, bool $onlyFieldsWithMigrationInstructions = false): array
    {
        if (array_key_exists('identifier', $dceConfiguration)) {
            $dceFields = $this->dceRepository->fetchFieldsByParentDce($dceConfiguration['uid']);
        } else {
            $dceFields = $this->dceRepository->fetchFieldsByParentField($dceConfiguration['uid']);
        }

        $fields = [];
        foreach ($dceFields as $dceField) {
            try {
                $fields[] = $this->buildFieldConfiguration($migrationInstructions[$dceField['variable']] ?? [], $dceField, $package, $convertType, $onlyFieldsWithMigrationInstructions);
            } catch (SkipFieldException $e) {
            }
        }

        return $fields;
    }

    protected function buildFieldConfiguration(array $fieldMigrationConfig, array $dceField, Package $package, bool $convertType, bool $onlyFieldsWithMigrationInstructions): array
    {
        if ($onlyFieldsWithMigrationInstructions && !$fieldMigrationConfig) {
            throw new SkipFieldException('Field should be skipped');
        }

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

            foreach ($this->fieldConfigurationMigrators as $fieldConfigurationMigrator) {
                $config = $fieldConfigurationMigrator->process($config);
            }

            if ($convertType) {
                $config['type'] = match ($config['type']) {
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
            }

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

                    $fieldConfiguration['fields'] = $this->buildFieldsConfiguration($fieldMigrationConfig['fields'], $dceField, $package);
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
            $templateContent = str_replace('field.', 'data.', $templateContent);
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
