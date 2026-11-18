<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * 1.20 `pointer_store: column` mapping checks: a child entity (map the root {@see Team} instead).
 */
#[ORM\Entity]
class SubTeam extends Team
{
}
