<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;

/**
 * 1.20 AuditKit bridge: an action log of user relations (to {@see AuditedUser}).
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_kit_test_relation_action_log')]
class RelationActionLog extends AbstractActionLog
{
}
