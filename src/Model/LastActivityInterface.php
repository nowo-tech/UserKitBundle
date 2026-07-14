<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use DateTimeInterface;

interface LastActivityInterface
{
    public function getLastActivityAt(): ?DateTimeInterface;

    public function setLastActivityAt(DateTimeInterface $lastActivityAt): void;
}
