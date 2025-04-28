<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Update;


use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\RepeatableInterface;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;
use WEBcoast\DceToContentblocks\Utility\UpgradeUtility;

#[UpgradeWizard('dce-to-contentblocks-content-element-upgrade')]
readonly class ContentElementUpgrade implements UpgradeWizardInterface, RepeatableInterface
{
    public function __construct(protected RecordDataMigratorFactory $recordDataMigratorFactory, protected UpgradeUtility $upgradeUtility) {}

    public function getTitle(): string
    {
        return 'DCE to content-blocks';
    }

    public function getDescription(): string
    {
        return 'Migrates all DCE based content elements using custom data migration classes';
    }

    public function executeUpdate(): bool
    {
        foreach ($this->recordDataMigratorFactory->getSupportedContentTypes() as $contentType) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
            $queryBuilder
                ->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            $queryBuilder
                ->select('*')
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter($contentType))
                );

            // Make sure, we process element per page, language and colPos
            $queryBuilder
                ->orderBy('pid')
                ->addOrderBy('sys_language_uid')
                ->addOrderBy('colPos')
                ->addOrderBy('sorting');

            $result = $queryBuilder->executeQuery();

            $this->upgradeUtility->migrateContentElements($result);
        }

        return true;
    }

    public function updateNecessary(): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in('CType', $queryBuilder->createNamedParameter($this->recordDataMigratorFactory->getSupportedContentTypes(), ArrayParameterType::STRING))
            );

        return $queryBuilder->executeQuery()->fetchOne() > 0;
    }

    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }
}
