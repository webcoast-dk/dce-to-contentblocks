<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Update;


use Doctrine\DBAL\ParameterType;
use Symfony\Contracts\Service\Attribute\Required;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

trait ContainerEnabledTrait
{
    protected Connection $connection;

    protected static array $elementByPidLanguageAndColPos = [];

    protected static int|string $lastContainerId = '';

    protected static int $lastRecordId = 0;

    protected static array $containerParents = [];

    #[Required]
    public function setConnection(ConnectionPool $connectionPool): void
    {
        $this->connection = $connectionPool->getConnectionForTable('tt_content');
    }

    protected function isFirstOfConsecutiveRecords(array $currentRecord, int $maxItemsInRow = PHP_INT_MAX): bool
    {
        // Fetch all records with the same colPos and pid for all languages (as CType is overriden in translation, when saving the original record)
        if (!(self::$elementByPidLanguageAndColPos[$currentRecord['pid'] . '-' . $currentRecord['sys_language_uid'] . '-' . $currentRecord['colPos']] ?? null)) {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($currentRecord['pid']);
            foreach ($site->getLanguages() as $language) {
                self::$elementByPidLanguageAndColPos[$currentRecord['pid'] . '-' . $language->getLanguageId() . '-' . $currentRecord['colPos']] = $this->connection->select(
                    ['uid', 'CType'],
                    'tt_content',
                    [
                        'colPos' => $currentRecord['colPos'],
                        'sys_language_uid' => $language->getLanguageId(),
                        'pid' => $currentRecord['pid'],
                    ],
                    [],
                    ['sorting' => 'ASC']
                )->fetchAllAssociative();
            }
        }

        $records = self::$elementByPidLanguageAndColPos[$currentRecord['pid'] . '-' . $currentRecord['sys_language_uid'] . '-' . $currentRecord['colPos']] ?? [];

        $consecutiveCount = 0;

        foreach ($records as $record) {
            if ($record['CType'] === $currentRecord['CType']) {
                ++$consecutiveCount;

                // Check if the current record starts a new row
                if ($record['uid'] === $currentRecord['uid'] && ($consecutiveCount - 1) % $maxItemsInRow === 0) {
                    return true;
                }

                // Reset the count when the maxItemsInRow limit is reached
                if ($consecutiveCount % $maxItemsInRow === 0) {
                    $consecutiveCount = 0;
                }
            } else {
                // Reset the count when a different CType is encountered
                $consecutiveCount = 0;
            }
        }

        return false;
    }

    protected function determinePreviousContentElementId(array $record): false|int
    {
        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($record['pid'], ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($record['sys_language_uid'], ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter($record['colPos'], ParameterType::INTEGER)),
                $queryBuilder->expr()->lte('sorting', $queryBuilder->createNamedParameter($record['sorting'], ParameterType::INTEGER))
            )
            ->orderBy('sorting', 'DESC')
            ->setMaxResults(1);

        return $queryBuilder->executeQuery()->fetchOne();
    }

    public function setNewIdMappings(array $newIdMappings): void
    {
        foreach ($newIdMappings as $newId => $realId) {
            if ($newId === self::$lastContainerId) {
                self::$lastContainerId = $realId;
            }

            foreach (self::$containerParents as $recordId => $containerId) {
                if ($containerId === $newId) {
                    self::$containerParents[$recordId] = $realId;
                }
            }
        }
    }
}
