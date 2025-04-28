<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use WEBcoast\DceToContentblocks\DependencyInjection\MigratorCollectionCompilerPass;
use WEBcoast\DceToContentblocks\Migration\FieldConfigurationMigratorFactory;
use WEBcoast\DceToContentblocks\Update\RecordDataMigratorCollection;

return function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $builder->addCompilerPass(new MigratorCollectionCompilerPass());

    $services = $container->services();

    $services
        ->set('webcoast.dce_to_contentblocks.ordered_migrators')
        ->class('array')
        ->factory([
            service(FieldConfigurationMigratorFactory::class),
            'getOrderedMigrators'
        ]);

    $services
        ->set('webcoast.dce_to_contentblocks.record_data_migrator_collection')
        ->class(RecordDataMigratorCollection::class)
        ->args([[]]);
};
