<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Update;


use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\RepeatableInterface;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;
use WEBcoast\DceToContentblocks\Utility\ConfigurationUtility;
use WEBcoast\DceToContentblocks\Utility\UpgradeUtility;

#[UpgradeWizard('dce-to-contentblocks-content-element-upgrade')]
readonly class ContentElementUpgrade implements UpgradeWizardInterface, RepeatableInterface
{
    public function __construct(protected ConfigurationUtility $configurationUtility, protected UpgradeUtility $upgradeUtility) {}

    public function getTitle(): string
    {
        return 'DCE to content-blocks';
    }

    public function getDescription(): string
    {
        return 'Migrates all DCE based content elements to their new content-blocks implementation.';
    }

    public function executeUpdate(): bool
    {
        $migrationConfiguration = $this->configurationUtility->buildMigrationConfiguration();

        foreach ($migrationConfiguration as $dceIdentifier => $migrationInstructions) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
            $queryBuilder
                ->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            $queryBuilder
                ->select('*')
                ->from('tt_content');

            if (is_int($dceIdentifier) || MathUtility::canBeInterpretedAsInteger($dceIdentifier)) {
                $queryBuilder->where(
                    $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('dce_dceuid' . $dceIdentifier))
                );
            } else {
                $queryBuilder->where(
                    $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('dce_' . $dceIdentifier))
                );
            }

            $result = $queryBuilder->executeQuery();

            $this->upgradeUtility->migrateContentElements($result, $migrationInstructions);
        }

        return true;
    }

    public function updateNecessary(): bool
    {
        $migrationConfiguration = $this->configurationUtility->buildMigrationConfiguration();

        $dceContentElementIdentifiers = [];
        foreach ($migrationConfiguration as $dceIdentifier => $migrationInstructions) {
            if (is_int($dceIdentifier) || MathUtility::canBeInterpretedAsInteger($dceIdentifier)) {
                $dceContentElementIdentifiers[] = 'dce_dceuid' . $dceIdentifier;
            } else {
                $dceContentElementIdentifiers[] = 'dce_' . $dceIdentifier;
            }
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in('CType', $queryBuilder->createNamedParameter($dceContentElementIdentifiers, ArrayParameterType::STRING))
            );

        return $queryBuilder->executeQuery()->fetchOne() > 0;
    }

    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }
}
