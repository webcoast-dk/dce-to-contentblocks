<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Migration;


use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('webcoast.dce_to_contentblocks.field_configuration_migrator')]
interface FieldConfigurationMigratorInterface
{
    public function process(array $fieldConfiguration): array;

    public function getDependencies(): array;
}
