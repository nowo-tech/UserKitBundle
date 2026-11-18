<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage\Fixtures;

use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjectInterface;

/**
 * 1.20 AuditKit bridge checks: a user class that says its own subject id (not its identifier).
 */
final class SubjectUser implements OwnerImageSubjectInterface
{
    public function getOwnerImageSubjectId(): string
    {
        return 'uuid-1';
    }

    public function getOwnerImageInitials(): string
    {
        return 'S';
    }
}
