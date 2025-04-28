<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\DependencyInjection;


use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use WEBcoast\DceToContentblocks\Attribute\SourceContentType;
use WEBcoast\DceToContentblocks\Update\RecordDataMigrator;

class MigratorCollectionCompilerPass implements CompilerPassInterface
{

    public function process(ContainerBuilder $container)
    {
        if (!$container->has('webcoast.dce_to_contentblocks.record_data_migrator_collection')) {
            return;
        }

        $definition = $container->findDefinition('webcoast.dce_to_contentblocks.record_data_migrator_collection');
        $taggedServices = $container->findTaggedServiceIds('webcoast.dce_to_contentblocks.record_data_migrator');

        $mapping = [];

        foreach ($taggedServices as $serviceId => $tags) {
            $serviceDefinition = $container->getDefinition($serviceId);
            $className = $serviceDefinition->getClass();

            if ($className === null) {
                continue;
            }

            $reflection = new \ReflectionClass($className);

            if (!$reflection->isSubclassOf(RecordDataMigrator::class)) {
                continue;
            }

            foreach ($reflection->getAttributes(SourceContentType::class) as $attribute) {
                /** @var SourceContentType $instance */
                $instance = $attribute->newInstance();
                $mapping[$instance->contentType] = $className;
            }
        }

        $definition->setArgument(0, $mapping);
    }
}
