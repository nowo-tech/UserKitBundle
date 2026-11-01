<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UserKitBundle\DependencyInjection\SessionsLoader;
use Nowo\UserKitBundle\Session\DefaultSessionInvalidator;
use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;
use Nowo\UserKitBundle\Sessions\Controller\SessionsController;
use Nowo\UserKitBundle\Sessions\RegistrySessionInvalidator;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compile-time guards and wiring of `nowo_user_kit.sessions`:
 *
 * - the registry needs SecurityBundle (token storage) and Doctrine;
 * - the registry backs {@see SessionInvalidatorInterface} unless the host aliased its own invalidator;
 * - the pages fail closed without form CSRF protection (REQ-SEC-005, pattern P2) or without Twig / FormKit, and refuse
 *   `security.allow_unauthenticated: true` outside `kernel.debug`.
 */
final class SessionsPass implements CompilerPassInterface
{
    /**
     * Applies the guards when the feature is enabled.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(UserSessionManager::class)) {
            return;
        }
        if (!$container->has('security.token_storage') || !$container->has('doctrine')) {
            throw new LogicException('NowoUserKitBundle: nowo_user_kit.sessions.enabled requires SecurityBundle and DoctrineBundle.');
        }
        if ($container->hasAlias(SessionInvalidatorInterface::class) && (string) $container->getAlias(SessionInvalidatorInterface::class) === DefaultSessionInvalidator::class) {
            $container->setAlias(SessionInvalidatorInterface::class, RegistrySessionInvalidator::class);
        }

        if (!$container->hasDefinition(SessionsController::class)) {
            return;
        }
        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;
        if (!$container->has('security.csrf.token_manager') || !$container->hasDefinition('form.type_extension.csrf') || $formCsrfDisabled) {
            throw new LogicException('NowoUserKitBundle: the session pages (sessions.web_ui.enabled: true) require CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection, or set nowo_user_kit.sessions.web_ui.enabled: false.');
        }
        if (!$container->has('twig') || !$container->has(CsrfOnlyFormFactory::class)) {
            throw new LogicException('NowoUserKitBundle: the session pages (sessions.web_ui.enabled: true) require TwigBundle, NowoUiKitBundle and NowoFormKitBundle to be registered.');
        }
        if ($container->hasParameter(SessionsLoader::ALLOW_UNAUTHENTICATED_PARAMETER)
            && $container->getParameter(SessionsLoader::ALLOW_UNAUTHENTICATED_PARAMETER) === true
            && (!$container->hasParameter('kernel.debug') || $container->getParameter('kernel.debug') !== true)) {
            throw new InvalidConfigurationException('nowo_user_kit.sessions.security.allow_unauthenticated: true is only allowed when kernel.debug is true (dev / demo): anybody could sign other users out. Set it to false and use security.manage_roles.');
        }
    }
}
