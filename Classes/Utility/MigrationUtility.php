<?php

declare(strict_types=1);

namespace WEBcoast\DceToContentblocks\Utility;

use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\ContentBlocks\Builder\ContentBlockBuilder;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentTypeIcon;
use TYPO3\CMS\ContentBlocks\Definition\Factory\UniqueIdentifierCreator;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;
use TYPO3\CMS\ContentBlocks\Service\PackageResolver;
use TYPO3\CMS\ContentBlocks\Utility\ContentBlockPathUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WEBcoast\DceToContentblocks\Repository\DceRepository;

readonly class MigrationUtility
{
    protected SymfonyStyle $io;

    protected string $targetExtensionKey;

    public function __construct(
        protected DceRepository $dceRepository,
        protected PackageResolver $packageResolver,
        protected FlexFormService $flexFormService,
        protected ContentBlockRegistry $contentBlockRegistry,
        protected ContentBlockBuilder $contentBlockBuilder,
        #[Autowire(service: 'webcoast.dce_to_contentblocks.ordered_migrators')]
        protected array $fieldConfigurationMigrators
    ) {
    }

    private static function buildContentBlockName(string $title): string
    {
        $title = preg_replace('/[^\w]/', '-', $title);
        $title = preg_replace('/-+/', '-', $title);
        $title = trim($title, '-');

        return strtolower(trim($title));
    }

    public function setIo(SymfonyStyle $io): void
    {
        $this->io = $io;
    }

    public function migrate(string $dceIdentifier): void
    {
        $dceConfiguration = $this->dceRepository->getConfiguration($dceIdentifier);

        $this->io->section('Migrating DCE "' . $dceIdentifier . '"');

        $this->targetExtensionKey = $this->io->askQuestion(new ChoiceQuestion('In which extension, should we place the content block?', $this->getPossibleExtensions(), null));
        $targetVendorName = $this->io->ask('What is the vendor name of the content block?', null, function ($value) {
            if (empty($value) || str_contains($value, '.') || str_contains($value, '/')) {
                throw new \RuntimeException('The vendor name of the content block must not be empty and must not contain a dot or a slash.');
            }

            return $value;
        });
        $targetContentBlockName = self::buildContentBlockName($dceConfiguration['title']);
        $targetContentBlockName = $this->io->ask('What is the name of the content block?', $targetContentBlockName, function ($value) {
            if (empty($value) || str_contains($value, '.') || str_contains($value, '/')) {
                throw new \RuntimeException('The name of the content block must not be empty and must not contain a dot or a slash.');
            }

            return $value;
        });

        if ($this->contentBlockRegistry->hasContentBlock($targetVendorName . '/' . $targetContentBlockName)) {
            throw new \RuntimeException('A content block "' . $targetVendorName . '/' . $targetContentBlockName . '" already exists.');
        }

        $prefixFields = $this->io->askQuestion(new ConfirmationQuestion('Do you want to prefix the fields with the content block name?', true));
        $prefixType = null;
        if ($prefixFields) {
            $prefixType = $this->io->askQuestion(new ChoiceQuestion('What is the prefix type?', ['full', 'vendor'], 'full'));
        }

        $fields = $this->dceRepository->fetchFieldsByParentDce($dceConfiguration['uid']);
        $fullName = $targetVendorName . '/' . $targetContentBlockName;
        $description = 'Description for ' . ContentType::CONTENT_ELEMENT->getHumanReadable() . ' ' . $fullName;
        $configuration = [
            'table' => 'tt_content',
            'typeField' => 'CType',
            'name' => $fullName,
            'typeName' => UniqueIdentifierCreator::createContentTypeIdentifier($fullName),
            'title' => $dceConfiguration['title'],
            'description' => $dceConfiguration['wizard_description'] ?: $description,
            'group' => $dceConfiguration['wizard_category'] ?: 'default',
            'prefixFields' => $prefixFields,
            'fields' => $this->buildFieldsConfiguration($fields)
        ];

        if ($prefixType) {
            $configuration['prefixType'] = $prefixType;
        }

        if (count(array_intersect(['space_before_class', 'space_after_class', 'layout', 'frame_class'], GeneralUtility::trimExplode(',', $dceConfiguration['palette_fields'])))) {
            $configuration['basics'][] = 'TYPO3/Appearance';
        }

        if ($dceConfiguration['show_category_tab'] ?? false) {
            $configuration['fields'][] = 'TYPO3/Categories';
        }

        $contentBlock = new LoadedContentBlock(
            $targetVendorName . '/' . $targetContentBlockName,
            $configuration,
            new ContentTypeIcon(),
            $this->targetExtensionKey,
            'EXT:' . $this->targetExtensionKey . '/' . ContentBlockPathUtility::getRelativeContentElementsPath(),
            ContentType::CONTENT_ELEMENT
        );

        $this->io->block('Configuration finished, saving content block "' . $contentBlock->getName() . '"', style: 'bg=green;fg=white', padding: true);

        $this->contentBlockBuilder->create($contentBlock);
        $this->copyTemplate($dceConfiguration, $contentBlock);
        $this->copyIcon($dceConfiguration, $contentBlock);

        if ($dceConfiguration['enable_container'] ?? false) {
            $this->buildContainerConfiguration($dceConfiguration, $configuration);
        }
    }

    private function buildContainerConfiguration(array $dceConfiguration, array $contentBlockConfiguration): void
    {
        $this->io->block('Building container configuration', style: 'bg=yellow;fg=black', padding: true);

        $fields = $this->dceRepository->fetchFieldsByParentDce($dceConfiguration['uid']);
        $containerFields = $this->buildFieldsConfiguration($fields, false);
        $labels = [];
        $columnsOverrides = [];
        $containerCType = $contentBlockConfiguration['typeName'] . '_container';
        $fieldIdentifiers = array_column($containerFields, 'identifier');
        $containerFields = array_map(function ($field, $fieldName) use (&$labels, &$columnsOverrides, $containerCType) {
            unset($field['identifier']);
            $labelKey = 'tt_content.' . $fieldName . '.label';
            if ($field['useExistingField'] ?? false) {
                $labelKey = 'tt_content.' . $fieldName . '.types.' . $containerCType . '_container.label';
                $labels[$labelKey] = $field['label'];
                unset($field['useExistingField'], $field['label']);
                $columnsOverrides[$fieldName]['config'] = $field;
                $field = null;
            } else {
                $labels[$labelKey] = $field['label'];
                unset($field['label']);
            }

            return [
                'label' => 'LLL:EXT:' . $this->targetExtensionKey . '/Resources/Private/Language/locallang_db.xlf:' . $labelKey,
                'config' => $field
            ];
        }, $containerFields, array_column($containerFields, 'identifier'));
        $containerFields = array_combine($fieldIdentifiers, $containerFields);

        $tcaOverridesFile = GeneralUtility::getFileAbsFileName('EXT:' . $this->targetExtensionKey . '/Configuration/TCA/Overrides/tt_content.php');
        TcaUtility::addContainerConfigurationToTcaOverrides($tcaOverridesFile, $containerCType, $dceConfiguration['wizard_category'] ?? 'container', ['CType' => $containerCType], $dceConfiguration['container_item_limit'] ?? 0);
        TcaUtility::addColumDefinitionsToTcaOverrides($tcaOverridesFile, $containerFields, $columnsOverrides, $containerCType);
        $xliffFile = GeneralUtility::getFileAbsFileName('EXT:' . $this->targetExtensionKey . '/Resources/Private/Language/locallang_db.xlf');
        $labels['tt_content.CType.' . $containerCType . '.label'] = $contentBlockConfiguration['title'] . ' Container';
        $labels['tt_content.CType.' . $containerCType . '.description'] = 'Description for ' . $contentBlockConfiguration['title'] . ' Container';
        $labels['tt_content.CType.' . $containerCType . '.content'] = 'Content';
        XliffUtility::addLabelsToFile($xliffFile, $labels, $this->targetExtensionKey);

        $this->io->block('Container configuration finished', style: 'bg=green;fg=white', padding: true);
    }

    private function buildFieldsConfiguration(array $fields, bool $convertType = true): array
    {
        $contentBlockFields = [];
        foreach ($fields as $field) {
            if ((int) $field['type'] === 1) {
                $this->io->writeln('<b>Tab:</b> ' . $field['title'] . ' (' . $field['variable'] . ')');
                if ($this->io->askQuestion(new ConfirmationQuestion('Do you want to process this tab?', true))) {
                    $contentBlockFields[] = $this->buildFieldConfiguration($field, $convertType);
                }
            } else {
                $this->io->writeln('<b>Field:</b> ' . $field['title'] . ' (' . $field['variable'] . ')');
                if ($this->io->askQuestion(new ConfirmationQuestion('Do you want to process this field?', true))) {
                    $contentBlockFields[] = $this->buildFieldConfiguration($field, $convertType);
                }
            }
        }

        return $contentBlockFields;
    }

    protected function buildFieldConfiguration(array $dceField, bool $convertType = true): array
    {
        $config = GeneralUtility::xml2array($dceField['configuration']);

        if ((int) $dceField['type'] === 1) {
            return [
                'identifier' => $this->io->askQuestion(
                    (new Question('What is the identifier of the tab?', $dceField['variable']))
                        ->setValidator(function ($value) {
                            if (empty($value)) {
                                throw new \RuntimeException('The identifier of the tab must not be empty.');
                            }

                            return $value;
                        })
                ),
                'label' => $this->io->askQuestion(
                    (new Question('What is the label of the field?', $dceField['title']))
                        ->setValidator(function ($value) {
                            if (empty($value)) {
                                throw new \RuntimeException('The label of the field must not be empty.');
                            }

                            return $value;
                        })
                )
            ];
        }

        $fieldConfiguration = [
            'identifier' => $dceField['map_to'] ?: $this->io->askQuestion(
                (new Question('What is the identifier of the field?', $dceField['variable']))
                    ->setValidator(function ($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('The identifier of the field must not be empty.');
                        }

                        return $value;
                    })
            ),
            'label' => $this->io->askQuestion(
                (new Question('What is the label of the field?', $dceField['title']))
                    ->setValidator(function ($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('The label of the field must not be empty.');
                        }

                        return $value;
                    })
            )
        ];

        if ($dceField['map_to']) {
            $fieldConfiguration['useExistingField'] = true;
        } else {
            if ($this->io->askQuestion(new ConfirmationQuestion('Do you want to use an existing field?', false))) {
                $fieldConfiguration['useExistingField'] = true;
            }
        }

        if ((int) $dceField['type'] === 0) {
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
        } elseif ((int) $dceField['type'] === 2) {
            $this->io->block('The field "' . $dceField['title'] . '" is a section type. Sections are converted to collections/inline records. Do want to build the collection configuration or convert the section to another field?', style: 'bg=yellow;fg=black', padding: true);

            if ($this->io->askQuestion(new ConfirmationQuestion('Do you want to build the collection configuration (yes) or convert to another field (no)?', true))) {
                $table = 'tx_' . str_replace('_', '', $this->targetExtensionKey) . '_domain_model_' . $fieldConfiguration['identifier'];
                $table = preg_replace('/([^s])s$/', '$1', $table);
                $fieldConfiguration = array_replace_recursive(
                    $fieldConfiguration,
                    [
                        'type' => 'Collection',
                        'table' => $this->io->askQuestion(
                            (new Question('What is the table name? Must start with "tx_' . str_replace('_', '', $this->targetExtensionKey) . '_"', $table))
                                ->setValidator(function ($value) use ($table) {
                                    if (empty($value)) {
                                        return $table;
                                    }
                                    if (!str_starts_with($value, 'tx_' . str_replace('_', '', $this->targetExtensionKey) . '_')) {
                                        throw new \RuntimeException('The table name must start with "tx_' . str_replace('_', '', $this->targetExtensionKey) . '_".');
                                    }

                                    return $value;
                                })
                        ),
                    ]
                );

                $this->io->info('Let\'s continue with the section fields.');

                $childFields = $this->dceRepository->fetchFieldsByParentField($dceField['uid']);
                $fieldConfiguration['fields'] = $this->buildFieldsConfiguration($childFields);
            } else {
                $this->io->info('Converting section "' . $dceField['title'] . '" to another field type.');
                $fieldConfiguration['type'] = $this->io->askQuestion(new ChoiceQuestion('What is the field type?', ['Category', 'Checkbox', 'Color', 'DateTime', 'Email', 'File', 'FlexForm', 'Folder', 'Relation', 'ImageManipulation', 'Collection', 'Text', 'Json', 'Language', 'Link', 'None', 'Number', 'Pass', 'Password', 'Radio', 'Select', 'Slug', 'Textarea', 'Uuid'], null));
                $fieldConfiguration['useExistingField'] = $this->io->askQuestion(new ConfirmationQuestion('Do you want to use an existing field?', true));
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

    protected function getPossibleExtensions(): array
    {
        $extensions = [];

        foreach ($this->packageResolver->getAvailablePackages() as $package) {
            $extensions[] = $package->getValueFromComposerManifest('extra')?->{'typo3/cms'}->{'extension-key'};
        }

        return $extensions;
    }
}
