<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;

/**
 * 1.20 AuditKit bridge: an action log that references users by id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_kit_test_id_action_log')]
class IdActionLog extends AbstractIdActionLog
{
}
