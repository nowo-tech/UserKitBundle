<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage\Fixtures;

/**
 * 1.20 compile-time checks: a class mapped in XML (no attributes): only the properties are checked at build time.
 */
class XmlMappedHost
{
    public ?string $uuid = null;

    public ?string $logo = null;
}
