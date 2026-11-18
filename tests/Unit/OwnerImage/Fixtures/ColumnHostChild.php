<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * 1.20 compile-time checks: a private mapped property of the parent class counts.
 */
#[ORM\Entity]
class ColumnHostChild extends ColumnHost
{
}
