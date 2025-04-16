<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use WEBcoast\DceToContentblocks\Migration\FieldConfigurationMigratorFactory;

return function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set('webcoast.dce_to_contentblocks.ordered_migrators')
        ->class('array')
        ->factory([
            service(FieldConfigurationMigratorFactory::class),
            'getOrderedMigrators'
        ]);
};
