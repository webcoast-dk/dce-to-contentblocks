<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Update;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\StringUtility;

#[AutoconfigureTag('webcoast.dce_to_contentblocks.record_data_migrator')]
#[Autoconfigure(public: true, shared: false)]
abstract class RecordDataMigrator
{
    protected array $referencedTableData = [];

    protected array $commandMap = [];

    protected string $targetContentType = '';

    abstract public function migrate(array $flexFormData, array $record): array;

    public function getTargetContentType(): string
    {
        if (empty($this->targetContentType)) {
            throw new \RuntimeException(sprintf('Target content type is not set in "%s". Please set it in the constructor or override the property $targetContentType.', get_class($this)), 1745832058);
        }

        return $this->targetContentType;
    }

    public function getReferencedTableData(): array
    {
        return $this->referencedTableData;
    }

    public function getCommandMap(): array
    {
        return $this->commandMap;
    }

    protected function addReference($table, $data, null|int|string $uid = null): int|string
    {
        if ($uid) {
            $this->referencedTableData[$table][$uid] = $data;

            return $uid;
        }

        $newUid = StringUtility::getUniqueId('NEW');
        $this->referencedTableData[$table][$newUid] = $data;

        return $newUid;
    }

    protected function addFileReference(File $file, string $tableName, string $fieldName, int|string $recordUid, int $pid, int $languageId, array $metaData = []): int|string
    {
        return $this->addReference('sys_file_reference', array_merge_recursive($metaData, [
            'pid' => $pid,
            'uid_local' => $file->getUid(),
            'uid_foreign' => $recordUid,
            'sys_language_uid' => $languageId,
            'tablenames' => $tableName,
            'fieldname' => $fieldName,
        ]));
    }

    protected function updateFileReference(FileReference $fileReference, string $fieldName, array $metaData = []): int
    {
        return $this->addReference('sys_file_reference', array_merge_recursive($metaData, [
            'fieldname' => $fieldName,
        ]), $fileReference->getUid());
    }

    protected function move(int|string $recordUid, array|int|string $destination, string $table = 'tt_content'): void
    {
        $this->commandMap[$table][$recordUid]['move'] = $destination;
    }

    protected function moveIntoContainer(int|string $recordUid, int|string $containerId, int $colPos, null|int|string $after = null): void
    {
        $this->move($recordUid, [
            'update' => [
                'tx_container_parent' => $containerId,
                'colPos' => $colPos,
            ],
            'action' => 'paste',
            'target' => $after ? '-' . $after : $containerId,
        ]);
    }

    protected function localize(int $recordUid, int $languageId, string $table = 'tt_content'): void
    {
        $this->commandMap[$table][$recordUid]['localize'] = $languageId;
    }
}
