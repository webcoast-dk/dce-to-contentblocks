<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Repository;


use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

class DceRepository
{
    public function fetchAll(): Result
    {
        $queryBuilder = $this->createQueryBuilder('tx_dce_domain_model_dce');
        $queryBuilder
            ->select('uid', 'title', 'identifier')
            ->from('tx_dce_domain_model_dce');

        return $queryBuilder->executeQuery();
    }

    public function createQueryBuilder(string $table): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
    }

    public function getConfiguration(int|string $dceIdentifier): array|false
    {
        $queryBuilder = $this->createQueryBuilder('tx_dce_domain_model_dce');
        $queryBuilder
            ->select('*')
            ->from('tx_dce_domain_model_dce');


        if (is_int($dceIdentifier) || MathUtility::canBeInterpretedAsInteger($dceIdentifier)) {
            $queryBuilder
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($dceIdentifier))
                );
        } else {
            $queryBuilder
                ->where(
                    $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($dceIdentifier))
                );
        }

        return $queryBuilder->executeQuery()->fetchAssociative();
    }

    public function fetchFieldsByParentDce(int $uid): array
    {
        $queryBuilder = $this->createQueryBuilder('tx_dce_domain_model_dcefield');
        $queryBuilder
            ->select('*')
            ->from('tx_dce_domain_model_dcefield')
            ->where(
                $queryBuilder->expr()->eq('parent_dce', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->orderBy('sorting', 'ASC');

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    public function fetchFieldsByParentField(int $uid): array
    {
        $queryBuilder = $this->createQueryBuilder('tx_dce_domain_model_dcefield');
        $queryBuilder
            ->select('*')
            ->from('tx_dce_domain_model_dcefield')
            ->where(
                $queryBuilder->expr()->eq('parent_field', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->orderBy('sorting', 'ASC');

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    public function fetchFieldByParentDce(int $uid, string $identifier): array|false
    {
        $queryBuilder = $this->createQueryBuilder('tx_dce_domain_model_dcefield');
        $queryBuilder
            ->select('*')
            ->from('tx_dce_domain_model_dcefield')
            ->where(
                $queryBuilder->expr()->eq('parent_dce', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->andWhere(
                $queryBuilder->expr()->eq('variable', $queryBuilder->createNamedParameter($identifier))
            );

        return $queryBuilder->executeQuery()->fetchAssociative();
    }

    public function fetchFieldByParentField(mixed $uid, string $identifier)
    {
        $queryBuilder = $this->createQueryBuilder('tx_dce_domain_model_dcefield');
        $queryBuilder
            ->select('*')
            ->from('tx_dce_domain_model_dcefield')
            ->where(
                $queryBuilder->expr()->eq('parent_field', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->andWhere(
                $queryBuilder->expr()->eq('variable', $queryBuilder->createNamedParameter($identifier))
            );

        return $queryBuilder->executeQuery()->fetchAssociative();
    }
}
