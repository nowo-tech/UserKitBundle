<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Session;

use Nowo\UserKitBundle\Session\DefaultSessionInvalidator;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DefaultSessionInvalidatorTest extends TestCase
{
    public function testNoOpInvalidation(): void
    {
        (new DefaultSessionInvalidator())->invalidateSessionsForUser(new stdClass());
        $this->addToAssertionCount(1);
    }
}
