<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Update;


interface ContainerAwareRecordDataMigratorInterface
{
    public function getContainerRecordData(): array;

    public function getContainerContentType(): string;
}
