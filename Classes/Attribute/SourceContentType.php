<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Attribute;

#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_CLASS)]
readonly class SourceContentType
{
    public function __construct(
        public string $contentType
    ) {}
}
