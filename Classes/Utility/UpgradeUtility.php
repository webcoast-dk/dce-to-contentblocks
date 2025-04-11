<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Utility;


use Doctrine\DBAL\Result;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\ContentBlocks\Definition\Factory\UniqueIdentifierCreator;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Package\Package;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use WEBcoast\DceToContentblocks\Event\AfterDataMigratedEvent;
use WEBcoast\DceToContentblocks\Repository\DceRepository;
use WEBcoast\DceToContentblocks\Update\MigrationHelperInterface;

class UpgradeUtility
{
    protected Connection $connection;

    public array $substNEWwithIDs = [];

    public array $insertStack = [];

    public array $updateStack = [];

    public function __construct(ConnectionPool $connectionPool, protected DceRepository $dceRepository, protected FlexFormService $flexFormService, protected EventDispatcherInterface $eventDispatcher)
    {
        $this->connection = $connectionPool->getConnectionForTable('tt_content');
    }

    public function migrateContentElements(Result $result, array $migrationInstructions): void
    {
        while ($record = $result->fetchAssociative()) {
            $data = [];

            if (str_starts_with($record['CType'], 'dce_dceuid')) {
                $dceIdentifier = (int) str_replace('dce_dceuid', '', $record['CType']);
            } else {
                $dceIdentifier = str_replace('dce_', '', $record['CType']);
            }

            $dceConfiguration = $this->dceRepository->getConfiguration($dceIdentifier);

            $dceFields = $this->dceRepository->fetchFieldsByParentDce($dceConfiguration['uid']);

            foreach ($dceFields as $dceField) {
                $oldField = $dceField['variable'];
                $migrationInstruction = $migrationInstructions['fields'][$oldField] ?? [];
                $newFieldName = $migrationInstruction['fieldName'] ?? $oldField;

                if (($migrationInstruction['skip'] ?? false) || (int) $dceField['type'] === 1) {
                    continue;
                }

                $this->addData($data, $record, $oldField, $newFieldName, $migrationInstruction, $dceField, $migrationInstructions['package']);
            }

            $afterDataMigratedEvent = new AfterDataMigratedEvent($data, $record, $migrationInstructions, $this);
            $this->eventDispatcher->dispatch($afterDataMigratedEvent);
            $data = $afterDataMigratedEvent->getData();

            $data['CType'] = UniqueIdentifierCreator::createContentTypeIdentifier($migrationInstructions['vendor'] . '/' . $migrationInstructions['identifier']);

            $this->connection->update(
                'tt_content',
                $data,
                ['uid' => $record['uid']]
            );

            foreach ($this->insertStack as $tables) {
                foreach ($tables as $table => $records) {
                    foreach ($records as &$record) {
                        foreach ($record as &$value) {
                            if ($this->substNEWwithIDs[$value] ?? null) {
                                $value = $this->substNEWwithIDs[$value];
                            }
                        }
                        $this->connection->insert($table, $record);
                    }
                }
            }

            foreach ($this->updateStack as $tables) {
                foreach ($tables as $table => $records) {
                    foreach ($records as $record) {
                        foreach ($record['data'] as &$value) {
                            if ($this->substNEWwithIDs[$value] ?? null) {
                                $value = $this->substNEWwithIDs[$value];
                            }
                        }
                        foreach ($record['identifier'] as &$value) {
                            if ($this->substNEWwithIDs[$value] ?? null) {
                                $value = $this->substNEWwithIDs[$value];
                            }
                        }
                        $this->connection->update(
                            $table,
                            $record['data'],
                            $record['identifier']
                        );
                    }
                }
            }

            $this->insertStack = [];
            $this->substNEWwithIDs = [];
        }
    }

    protected function addData(array &$data, array $record, string $oldFieldName, string $newFieldName, array $migrationInstruction, array $dceField, Package $package): void
    {
        if ($migrationInstruction['value'] ?? null && is_callable($migrationInstruction['value'])) {
            $flexFormData = ($record['pi_flexform'] ?? null) ? $this->flexFormService->convertFlexFormContentToArray($record['pi_flexform'])['settings'] : [];
            $data[$newFieldName] = $migrationInstruction['value']($flexFormData, $record);
        } elseif ($migrationInstruction['migrationHelper'] ?? null) {
            $migrationHelper = GeneralUtility::makeInstance($migrationInstruction['migrationHelper']);
            if (!$migrationHelper instanceof MigrationHelperInterface) {
                throw new \RuntimeException('Migration helper must implement ' . MigrationHelperInterface::class, 1741168030);
            }

            $migrationHelper->migrate($data, $record, $oldFieldName, $newFieldName, $migrationInstruction, $dceField, $this);
        } else {
            if ((int) $dceField['type'] === 0) {
                $this->addDataForField($data, $record, $oldFieldName, $newFieldName, $migrationInstruction, $dceField);
            } elseif ((int) $dceField['type'] === 2) {
                $this->addDataForSection($data, $record, $oldFieldName, $newFieldName, $migrationInstruction, $dceField, $package);
            }
        }
    }

    protected function addDataForField(array &$data, array $record, string $oldFieldName, string $newFieldName, array $migrationInstruction, array $dceField): void
    {
        if ($dceField['map_to']) {
            return;
        }

        $flexFormData = ($record['pi_flexform'] ?? null) ? $this->flexFormService->convertFlexFormContentToArray($record['pi_flexform'])['settings'] : $record;
        $dceFieldConfiguration = GeneralUtility::xml2array($dceField['configuration'] ?? '') ?? [];
        if ($dceFieldConfiguration['type'] === 'group' && ($dceFieldConfiguration['internal_type'] ?? '') === 'file') {
            if ($migrationInstruction['mergeWith'] ?? null) {
                $newFieldName = $migrationInstruction['mergeWith'];
            }
            $filesNames = GeneralUtility::trimExplode(',', $flexFormData[$oldFieldName] ?? '', true);

            /** @var StorageRepository $storageRepository */
            $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
            $fileReferencesToInsert = [];
            $otherFileReferencesCount = $this->countFileReferenceForField($newFieldName, $record);
            foreach ($filesNames as $fileName) {
                $fileIdentifier = ltrim(($dceFieldConfiguration['uploadfolder'] ?? '') . '/' . $fileName, '/');
                $folderName = dirname($fileIdentifier);
                $storage = $storageRepository->getStorageObject(0, [], $fileIdentifier);
                $file = $storage->getFile($fileIdentifier);
                $targetStorage = $storageRepository->getDefaultStorage();
                if (!$targetStorage->hasFolder($folderName)) {
                    $targetFolder = $targetStorage->createFolder($folderName);
                } else {
                    $targetFolder = $targetStorage->getFolder($folderName);
                }
                $targetStorage->copyFile($file, $targetFolder, $file->getName());
                $fileReferenceData = [
                    'uid_local' => $file->getUid(),
                    'uid_foreign' => $record['uid'],
                    'tablenames' => $record['tableName'] ?? 'tt_content',
                    'fieldname' => $newFieldName,
                    'sorting_foreign' => ($otherFileReferencesCount + count($fileReferencesToInsert) + 1) * 256,
                ];

                $this->addFileReferenceData($fileReferenceData, $migrationInstruction, $flexFormData);

                $fileReferencesToInsert[] = $fileReferenceData;
            }
            array_unshift($this->insertStack, [
                'sys_file_reference' => $fileReferencesToInsert,
            ]);
            $data[$newFieldName] = count($fileReferencesToInsert);
        } elseif ($dceFieldConfiguration['type'] === 'group' && $dceFieldConfiguration['internal_type'] === 'db' && ($dceFieldConfiguration['appearance']['elementBrowserType'] ?? '') === 'file') {
            if ($migrationInstruction['mergeWith'] ?? null) {
                $newFieldName = $migrationInstruction['mergeWith'];
            }
            $fileIds = GeneralUtility::intExplode(',', $flexFormData[$oldFieldName], true);
            $fileReferencesToInsert = [];
            $otherFileReferencesCount = $this->countFileReferenceForField($newFieldName, $record);
            foreach ($fileIds as $fileId) {
                $fileReferenceData = [
                    'uid_local' => $fileId,
                    'uid_foreign' => $record['uid'],
                    'tablenames' => $record['tableName'] ?? 'tt_content',
                    'fieldname' => $newFieldName,
                    'sorting_foreign' => ($otherFileReferencesCount + count($fileReferencesToInsert) + 1) * 256,
                ];

                $fileReferenceData = $this->addFileReferenceData($fileReferenceData, $migrationInstruction, $flexFormData);

                $fileReferencesToInsert[] = $fileReferenceData;
            }

            array_unshift($this->insertStack, [
                'sys_file_reference' => $fileReferencesToInsert,
            ]);
            $data[$newFieldName] = count($fileReferencesToInsert);
        } elseif (
            (
                $dceFieldConfiguration['type'] === 'inline'
                && ($dceFieldConfiguration['foreign_table'] ?? '') === 'sys_file_reference'
            )
            || ($dceFieldConfiguration['type'] === 'file')
        ) {
            if ($migrationInstruction['mergeWith'] ?? null) {
                $newFieldName = $migrationInstruction['mergeWith'];
            }
            $otherFileReferencesCount = $this->countFileReferenceForField($newFieldName, $record);
            $fileReferenceData = [
                'fieldname' => $newFieldName,
                'sorting_foreign' => ($otherFileReferencesCount + 1) * 256,
            ];

            $fileReferenceData = $this->addFileReferenceData($fileReferenceData, $migrationInstruction, $flexFormData);

            array_unshift($this->updateStack, [
                'sys_file_reference' => [
                    [
                        'data' => $fileReferenceData,
                        'identifier' => [
                            'uid_foreign' => $record['uid'],
                            'tablenames' => $record['tableName'] ?? 'tt_content',
                            'fieldname' => 'settings.' . $oldFieldName,
                        ]
                    ]
                ],
            ]);
            $data[$newFieldName] = $flexFormData[$oldFieldName] ?? 0;
        } else {
            if (is_callable($migrationInstruction['value'] ?? null)) {
                $data[$newFieldName] = $migrationInstruction['value']($flexFormData);
            } elseif ($migrationInstruction['value'] ?? null) {
                $data[$newFieldName] = $migrationInstruction['value'];
            } else {
                if ($migrationInstruction['trim'] ?? false) {
                    $data[$newFieldName] = trim($flexFormData[$oldFieldName] ?? '');
                } else {
                    $data[$newFieldName] = $flexFormData[$oldFieldName] ?? '';
                }
            }

        }
    }

    protected function addDataForSection(array &$data, array $record, string $oldFieldName, string $newFieldName, array $migrationInstructions, array $dceField, Package $package): void
    {
        $tableName = $migrationInstructions['table'] ?? null;
        if (!$tableName) {
            $extensionName = str_replace('_', '', $package->getValueFromComposerManifest('extra')?->{'typo3/cms'}?->{'extension-key'} ?? '');
            $tableName = 'tx_' . $extensionName . '_domain_model_' . ($migrationInstructions['fieldName'] ?? $dceField['variable']);
        }

        $flexFormData = $this->flexFormService->convertFlexFormContentToArray($record['pi_flexform']);
        $sections = ($flexFormData['settings'][$oldFieldName] ?? null) ?: [];
        $count = 0;

        foreach ($sections as $section) {
            $childFields = $this->dceRepository->fetchFieldsByParentField($dceField['uid']);

            if ($migrationInstructions['traverse'] ?? null) {
                foreach ($childFields as $childField) {
                    $oldChildFieldName = $childField['variable'];
                    $migrationInstruction = $migrationInstructions['fields'][$oldChildFieldName] ?? [];
                    if ($migrationInstruction['skip'] ?? false) {
                        continue;
                    }
                    $newChildFieldName = $migrationInstruction['fieldName'] ?? $oldChildFieldName;
                    $recordData = $section['container_' . $oldFieldName] ?? [];
                    $recordData['uid'] = $record['uid'];
                    $recordData['tableName'] = 'tt_content';
                    $this->addData($data, $recordData, $oldChildFieldName, $newChildFieldName, $migrationInstruction, $childField, $package);
                }
            } else {
                $childData = [];
                $newId = StringUtility::getUniqueId('NEW');
                foreach ($childFields as $childField) {
                    $oldChildFieldName = $childField['variable'];
                    $migrationInstruction = $migrationInstructions['fields'][$oldChildFieldName] ?? [];
                    if ($migrationInstruction['skip'] ?? false) {
                        continue;
                    }
                    $newChildFieldName = $migrationInstruction['fieldName'] ?? $oldChildFieldName;
                    $recordData = $section['container_' . $oldFieldName] ?? [];
                    $recordData['uid'] = $tableName . '_' . $newId;
                    $recordData['tableName'] = $tableName;
                    $this->addData($childData, $recordData, $oldChildFieldName, $newChildFieldName, $migrationInstruction, $childField, $package);
                }
                $childData[$migrationInstructions['foreign_field'] ?? 'foreign_table_parent_uid'] = $record['uid'];
                $this->connection->insert(
                    $tableName,
                    $childData
                );
                $this->substNEWwithIDs[$tableName . '_' . $newId] = $this->connection->lastInsertId();
            }

            ++$count;
        }

        $data[$newFieldName] = $count;
    }

    protected function countFileReferenceForField(string $fieldName, array $record): int
    {
        $insertStacks = array_filter($this->insertStack, function ($item) {
            return array_key_exists('sys_file_reference', $item);
        });
        $insertFileReferences = [];
        array_walk($insertStacks, function ($insertStack) use (&$insertFileReferences) {
            array_walk($insertStack['sys_file_reference'], function ($insertInstructions) use (&$insertFileReferences) {
                $insertFileReferences[] = $insertInstructions;
            });
        });
        $insertFileReferences = array_filter($insertFileReferences, function ($item) use ($fieldName, $record) {
            return $item['fieldname'] === $fieldName && $item['uid_foreign'] === $record['uid'] && $item['tablenames'] === ($record['tableName'] ?? 'tt_content');
        });

        $updateStacks = array_filter($this->updateStack, function ($item) {
            return array_key_exists('sys_file_reference', $item);
        });
        $otherFileReferences = [];
        array_walk($updateStacks, function ($updateStack) use (&$otherFileReferences) {
            array_walk($updateStack['sys_file_reference'], function ($updateInstructions) use (&$otherFileReferences) {
                $otherFileReferences[] = $updateInstructions;
            });
        });
        $otherFileReferences = array_filter($otherFileReferences, function ($item) use ($fieldName, $record) {
            return $item['data']['fieldname'] === $fieldName && $item['identifier']['uid_foreign'] === $record['uid'] && $item['identifier']['tablenames'] === ($record['tableName'] ?? 'tt_content');
        });

        return count($insertFileReferences) + count($otherFileReferences);
    }

    protected function addFileReferenceData(array $fileReferenceData, array $migrationInstruction, array $flexFormData): array
    {
        foreach ($migrationInstruction['fields'] ?? [] as $fieldName => $fieldConfiguration) {
            if (array_key_exists($fieldName, $fileReferenceData)) {
                continue;
            }

            if ($fieldConfiguration['dataFrom'] ?? null && ($flexFormData[$fieldConfiguration['dataFrom']] ?? null)) {
                if ($fieldConfiguration['trim'] ?? false) {
                    $fileReferenceData[$fieldName] = trim($flexFormData[$fieldConfiguration['dataFrom']]);
                } else {
                    $fileReferenceData[$fieldName] = $flexFormData[$fieldConfiguration['dataFrom']];
                }
            }
        }

        return $fileReferenceData;
    }

    public function addFileReference(FileInterface $file, int $recordUid, int $pid, string $table, string $fieldName, array $metaData = [])
    {
        $countFileReferences = $this->countFileReferenceForField($fieldName, ['uid' => $recordUid]);
        $this->insertStack[] = [
            'sys_file_reference' => [
                array_merge(
                    [
                        'pid' => $pid,
                        'uid_local' => $file->getUid(),
                        'uid_foreign' => $recordUid,
                        'tablenames' => $table,
                        'fieldname' => $fieldName,
                        'table_local' => 'sys_file',
                        'sorting_foreign' => ($countFileReferences + 1) * 256,
                    ],
                    array_filter($metaData, function ($key) {
                        return !in_array($key, [
                            'uid',
                            'pid',
                            'tstamp',
                            'crdate',
                            'deleted',
                            'hidden',
                            'sys_language_uid',
                            'l10n_parent',
                            'l10n_state',
                            'l10n_diffsource',
                            't3ver_oid',
                            't3ver_id',
                            't3ver_label',
                            't3ver_wsid',
                            't3ver_state',
                            't3ver_stage',
                            't3ver_count',
                            't3ver_tstamp',
                            't3ver_move_id',
                            'uid_local',
                            'uid_foreign',
                            'tablenames',
                            'fieldname',
                            'sorting_foreign',
                            'table_local',
                        ]);
                    }, ARRAY_FILTER_USE_KEY)
                )
            ]
        ];
    }
}
