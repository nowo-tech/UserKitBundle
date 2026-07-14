<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle;

use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NowoUserKitBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoUserKitBundle';

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new NowoUserKitExtension();
        }

        return $this->extension;
    }
}
