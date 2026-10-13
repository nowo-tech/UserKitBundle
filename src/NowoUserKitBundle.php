<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle;

use Nowo\UserKitBundle\DependencyInjection\Compiler\AccountStatusUserCheckerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NowoUserKitBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoUserKitBundle';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AccountStatusUserCheckerPass());
    }
}
