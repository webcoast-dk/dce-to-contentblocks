<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Migration;


use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use TYPO3\CMS\Core\Service\DependencyOrderingService;

readonly class FieldConfigurationMigratorFactory
{
    /**
     * @param DependencyOrderingService $dependencyOrderingService
     * @param iterable<FieldConfigurationMigratorInterface> $fieldConfigurationMigrators
     */
    public function __construct(
        protected DependencyOrderingService $dependencyOrderingService,
        #[AutowireIterator(tag: 'webcoast.dce_to_contentblocks.field_configuration_migrator')]
        protected iterable $fieldConfigurationMigrators
    ) {}

    /**
     * @return FieldConfigurationMigratorInterface[]
     */
    public function getOrderedMigrators(): array
    {
        $fieldConfigurationMigrators = [];
        foreach ($this->fieldConfigurationMigrators as $migrator) {
            if (!is_subclass_of($migrator, FieldConfigurationMigratorInterface::class)) {
                throw new \RuntimeException(sprintf('Class %s is not a valid migrator', $migrator));
            }

            $fieldConfigurationMigrators[get_class($migrator)] = [
                'migrator' => $migrator,
                'before' => $migrator->getDependencies()['before'] ?? [],
                'after' => $migrator->getDependencies()['after'] ?? []
            ];
        }

        return array_column($this->dependencyOrderingService->orderByDependencies($fieldConfigurationMigrators), 'migrator');
    }
}
