<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\EventListener;

use Nowo\UserKitBundle\OwnerImage\Twig\OwnerImageExtension;

/**
 * Clears the request memo of {@see OwnerImageExtension} at every request boundary (`kernel.request` of the main
 * request, `kernel.terminate`) and after each Messenger message, without relying on the services resetter (FrankenPHP
 * worker mode with kernel reset false, REQ-RUNTIME-002). Stateless itself.
 */
final readonly class OwnerImageMemoListener
{
    public function __construct(
        private OwnerImageExtension $extension,
    ) {
    }

    /**
     * Any boundary event: forget the memo.
     */
    public function __invoke(): void
    {
        $this->extension->reset();
    }
}
