<?php

declare(strict_types=1);

namespace WEBcoast\DceToContentblocks\Utility;

use Doctrine\DBAL\Result;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WEBcoast\DceToContentblocks\Repository\DceRepository;
use WEBcoast\DceToContentblocks\Update\NewIdMappingAwareInterface;
use WEBcoast\DceToContentblocks\Update\RecordDataMigratorFactory;

class UpgradeUtility implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const COLLECTION_PARENT_FIELD = 'foreign_table_parent_uid';

    protected Connection $connection;

    protected array $lastContainerIds = [];

    protected array $preMigrationElementsByPid = [];

    protected array $containerParentIds = [];

    public function __construct(
        ConnectionPool $connectionPool,
        protected RecordDataMigratorFactory $recordDataMigratorFactory,
        protected DceRepository $dceRepository,
        protected FlexFormService $flexFormService,
        protected TcaSchemaFactory $tcaSchemaFactory,
        protected LanguageServiceFactory $languageServiceFactory
    ) {
        $this->connection = $connectionPool->getConnectionForTable('tt_content');
    }

    public function migrateContentElements(Result $result): void
    {
        while ($record = $result->fetchAssociative()) {

            $rawFlexFormData = $this->flexFormService->convertFlexFormContentToArray($record['pi_flexform'])['settings'] ?? [];
            $data = [];

            if (str_starts_with($record['CType'], 'dce_dceuid')) {
                $dceIdentifier = (int) str_replace('dce_dceuid', '', $record['CType']);
            } else {
                $dceIdentifier = str_replace('dce_', '', $record['CType']);
            }

            $dceConfiguration = $this->dceRepository->getConfiguration($dceIdentifier);
            $dceFields = $this->dceRepository->fetchFieldsByParentDce($dceConfiguration['uid']);

            foreach ($dceFields as $dceField) {
                if ((int) $dceField['type'] === 1) {
                    // Skip tab fields, as they hold no data
                    continue;
                }

                $this->addData($data, $rawFlexFormData, $record, $dceField);
            }

            $recordDataMigrator = $this->recordDataMigratorFactory->getMigrator($record['CType']);
            $dataMap = [
                'tt_content' => [
                    $record['uid'] => $recordDataMigrator->migrate($data, $record),
                ],
            ];

            $referencedTableData = $recordDataMigrator->getReferencedTableData();
            foreach ($referencedTableData as $tableName => &$records) {
                $schema = $this->tcaSchemaFactory->get($tableName);
                foreach ($records as &$recordData) {
                    if (!($recordData['pid'] ?? null)) {
                        $recordData['pid'] = $record['pid'];
                    }

                    if ($schema->isLanguageAware() && !($recordData[$schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName()] ?? null)) {
                        $recordData[$schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName()] = $record['sys_language_uid'];
                    }
                }
            }
            $dataMap = array_replace_recursive($referencedTableData, $dataMap);

            if (!Environment::isCli()) {
                Bootstrap::initializeBackendUser(BackendUserAuthentication::class, ServerRequestFactory::fromGlobals());
                Bootstrap::initializeBackendAuthentication();
                $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER']);
            }

            // Update the CType beforehand, because some data handling logic relies on the new CType
            $this->connection->update(
                'tt_content',
                [
                    'CType' => $recordDataMigrator->getTargetContentType(),
                ],
                [
                    'uid' => $record['uid'],
                ]
            );

            /** @var DataHandler $dataHandler */
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->bypassWorkspaceRestrictions = true;
            $dataHandler->start($dataMap, $recordDataMigrator->getCommandMap());
            $dataHandler->process_datamap();
            $dataHandler->process_cmdmap();

            if ($recordDataMigrator instanceof NewIdMappingAwareInterface) {
                $recordDataMigrator->setNewIdMappings($dataHandler->substNEWwithIDs);
            }
        }
    }

    public function addData(array &$data, array $rawFlexFormData, array $record, array $dceField): void
    {
        if ((int) $dceField['type'] === 0) {
            $this->addDataForField($data, $rawFlexFormData, $record, $dceField);
        } elseif ((int) $dceField['type'] === 2) {
            $this->addDataForSection($data, $rawFlexFormData, $record, $dceField);
        }
    }

    protected function addDataForField(array &$data, array $rawFlexFormData, array $record, array $dceField): void
    {
        if ($dceField['map_to']) {
            return;
        }

        $dceFieldConfiguration = GeneralUtility::xml2array($dceField['configuration'] ?? '') ?? [];
        if ($dceFieldConfiguration['type'] === 'group' && ($dceFieldConfiguration['internal_type'] ?? '') === 'file') {
            $filesNames = GeneralUtility::trimExplode(',', $rawFlexFormData[$dceField['variable']] ?? '', true);

            $data[$dceField['variable']] = [];

            /** @var StorageRepository $storageRepository */
            $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
            foreach ($filesNames as $fileName) {
                $fileIdentifier = ltrim(($dceFieldConfiguration['uploadfolder'] ?? '') . '/' . $fileName, '/');
                $storage = $storageRepository->getStorageObject(0, [], $fileIdentifier);

                try {
                    $file = $storage->getFile($fileIdentifier);
                    if ($storage->getUid() === 0) {
                        $defaultStorage = $storageRepository->getDefaultStorage();
                        if (!$defaultStorage->hasFolder(dirname($fileIdentifier))) {
                            $defaultStorage->createFolder(dirname($fileIdentifier));
                        }
                        $targetFolder = $defaultStorage->getFolder(dirname($fileIdentifier));
                        if (!$targetFolder->hasFile($fileName)) {
                            $newFile = $file->copyTo($targetFolder);
                        } elseif ($targetFolder->getFile($fileName)->getSha1() === $file->getSha1()) {
                            $newFile = $targetFolder->getFile($fileName);
                        } else {
                            $newFile = $file->copyTo($targetFolder);
                        }
                    }
                    $data[$dceField['variable']][] = $newFile ?? $file;
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage());
                }
            }
        } elseif ($dceFieldConfiguration['type'] === 'group' && $dceFieldConfiguration['internal_type'] === 'db' && ($dceFieldConfiguration['appearance']['elementBrowserType'] ?? '') === 'file') {
            $fileIds = GeneralUtility::intExplode(',', $rawFlexFormData[$dceField['variable']] ?? '', true);
            $data[$dceField['variable']] = [];
            foreach ($fileIds as $fileId) {
                $fileRepository = GeneralUtility::makeInstance(FileRepository::class);
                $file = $fileRepository->findByUid($fileId);

                $data[$dceField['variable']][] = $file;
            }
        } elseif (($dceFieldConfiguration['type'] === 'inline' && ($dceFieldConfiguration['foreign_table'] ?? '') === 'sys_file_reference') || ($dceFieldConfiguration['type'] === 'file')) {
            $data[$dceField['variable']] = [];

            /** @var RelationHandler $relationHandler */
            $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
            $relationHandler->initializeForField('tt_content', array_replace_recursive($dceFieldConfiguration, ['foreign_match_fields' => ['fieldname' => 'settings.' . str_replace('{$variable}', $dceField['variable'], $dceFieldConfiguration['foreign_match_fields']['fieldname'])]]), $record['uid']);
            if (!empty($relationHandler->tableArray['sys_file_reference'])) {
                $relationHandler->processDeletePlaceholder();
                $referenceUids = $relationHandler->tableArray['sys_file_reference'];

                /** @var ResourceFactory $resourceFactory */
                $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
                foreach ($referenceUids as $referenceUid) {
                    $data[$dceField['variable']][] = $resourceFactory->getFileReferenceObject($referenceUid);
                }
            }

            $relationHandler->initializeForField('tt_content', array_replace_recursive($dceFieldConfiguration, ['foreign_match_fields' => ['fieldname' => str_replace('{$variable}', $dceField['variable'], $dceFieldConfiguration['foreign_match_fields']['fieldname'])]]), $record['uid']);
            if (!empty($relationHandler->tableArray['sys_file_reference'])) {
                $relationHandler->processDeletePlaceholder();
                $referenceUids = $relationHandler->tableArray['sys_file_reference'];

                /** @var ResourceFactory $resourceFactory */
                $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
                foreach ($referenceUids as $referenceUid) {
                    $data[$dceField['variable']][] = $resourceFactory->getFileReferenceObject($referenceUid);
                }
            }
        } else {
            $data[$dceField['variable']] = $rawFlexFormData[$dceField['variable']] ?? '';
        }
    }

    protected function addDataForSection(array &$data, array $rawFlexFormData, array $record, array $dceField): void
    {
        $sections = $rawFlexFormData[$dceField['variable']] ?? [];
        $data[$dceField['variable']] = [];

        foreach ($sections as $section) {
            $childFields = $this->dceRepository->fetchFieldsByParentField($dceField['uid']);
            $childData = [];
            foreach ($childFields as $childField) {
                if ((int) $childField['type'] === 1) {
                    // Skip tab fields, as they hold no data
                    continue;
                }
                $childFlexFormData = $section['container_' . $dceField['variable']] ?? [];
                $this->addData($childData, $childFlexFormData, $record, $childField);
            }

            $data[$dceField['variable']][] = $childData;
        }
    }
}
