<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle;

use Nowo\UserKitBundle\DependencyInjection\Compiler\AccountStatusUserCheckerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * User Kit bundle: account status, last activity / presence, UI preferences, product tour and personal data tools.
 */
final class NowoUserKitBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoUserKitBundle';

    /**
     * Registers the compiler pass that attaches the account status user checker to every firewall.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AccountStatusUserCheckerPass());
    }
}
