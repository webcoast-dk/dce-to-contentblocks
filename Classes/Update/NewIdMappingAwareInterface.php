<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Update;


interface NewIdMappingAwareInterface
{
    public function setNewIdMappings(array $newIdMappings): void;
}
