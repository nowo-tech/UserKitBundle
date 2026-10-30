<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection\Compiler;

use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function is_array;
use function is_string;

/**
 * Wires {@see AccountStatusUserChecker} into every security-enabled firewall.
 *
 * SecurityBundle does not consume a global `security.user_checker` tag: each firewall
 * resolves `security.user_checker.<firewall>` (an alias to the configured `user_checker`,
 * `security.user_checker` by default) and only the per-firewall
 * `security.user_checker.chain.<firewall>` collects services tagged
 * `security.user_checker.<firewall>`.
 *
 * For each firewall this pass tags UserKit's checker for the firewall chain and, unless the
 * firewall already uses that chain (or UserKit's checker directly), points the firewall checker
 * alias at the chain and tags the previously configured checker into it, so both run.
 *
 * Opt out per profile with `account_status.enabled: false` (the checker service is then removed).
 */
final class AccountStatusUserCheckerPass implements CompilerPassInterface
{
    /**
     * Attaches the account status user checker to every security-enabled firewall.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AccountStatusUserChecker::class) || !$container->hasParameter('security.firewalls')) {
            return;
        }

        $firewalls = $container->getParameter('security.firewalls');
        if (!is_array($firewalls)) {
            return;
        }

        $checker = $container->getDefinition(AccountStatusUserChecker::class);

        foreach ($firewalls as $firewall) {
            if (!is_string($firewall)) {
                continue;
            }

            $aliasId = 'security.user_checker.' . $firewall;
            $chainId = 'security.user_checker.chain.' . $firewall;

            // Firewalls with "security: false" register neither the alias nor the chain.
            if (!$container->hasAlias($aliasId) || !$container->hasDefinition($chainId)) {
                continue;
            }

            $current = (string) $container->getAlias($aliasId);
            if ($current === AccountStatusUserChecker::class) {
                continue;
            }

            $tag = 'security.user_checker.' . $firewall;
            $this->addTagOnce($checker, $tag);

            if ($chainId === $current) {
                continue;
            }

            // Keep the previously configured checker (Symfony default or app service) in the chain.
            $this->addTagOnce($container->findDefinition($current), $tag, ['priority' => 10]);
            $container->setAlias($aliasId, $chainId)->setPublic(false);
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function addTagOnce(Definition $definition, string $tag, array $attributes = []): void
    {
        if (!$definition->hasTag($tag)) {
            $definition->addTag($tag, $attributes);
        }
    }
}
