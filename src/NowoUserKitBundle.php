<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle;

use Nowo\UserKitBundle\DependencyInjection\Compiler\AccountStatusUserCheckerPass;
use Nowo\UserKitBundle\DependencyInjection\Compiler\SessionsPass;
use Nowo\UserKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * User Kit bundle: account status, last activity / presence, UI preferences, product tour and personal data tools, session registry.
 */
final class NowoUserKitBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoUserKitBundle';

    /**
     * Registers the compiler passes: account status user checker on every firewall, session registry guards, Twig paths.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AccountStatusUserCheckerPass());
        $container->addCompilerPass(new SessionsPass());
        $container->addCompilerPass(new TwigPathsPass());
    }
}
