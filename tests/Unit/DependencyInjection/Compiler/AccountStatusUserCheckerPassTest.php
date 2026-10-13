<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\UserKitBundle\DependencyInjection\Compiler\AccountStatusUserCheckerPass;
use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Core\User\ChainUserChecker;
use Symfony\Component\Security\Core\User\InMemoryUserChecker;

final class AccountStatusUserCheckerPassTest extends TestCase
{
    public function testDefaultFirewallCheckerIsReplacedByChainContainingBothCheckers(): void
    {
        $container = $this->createContainer(['main' => 'security.user_checker']);

        (new AccountStatusUserCheckerPass())->process($container);

        $this->assertSame('security.user_checker.chain.main', (string) $container->getAlias('security.user_checker.main'));
        $this->assertFalse($container->getAlias('security.user_checker.main')->isPublic());
        $this->assertSame([[]], $container->getDefinition(AccountStatusUserChecker::class)->getTag('security.user_checker.main'));
        $this->assertSame([['priority' => 10]], $container->getDefinition('security.user_checker')->getTag('security.user_checker.main'));
    }

    public function testFirewallAlreadyUsingChainOnlyGetsTheTag(): void
    {
        $container = $this->createContainer(['main' => 'security.user_checker.chain.main']);
        $container->getDefinition(AccountStatusUserChecker::class)->addTag('security.user_checker.main');

        (new AccountStatusUserCheckerPass())->process($container);

        $this->assertSame('security.user_checker.chain.main', (string) $container->getAlias('security.user_checker.main'));
        $this->assertCount(1, $container->getDefinition(AccountStatusUserChecker::class)->getTag('security.user_checker.main'));
        $this->assertFalse($container->getDefinition('security.user_checker')->hasTag('security.user_checker.main'));
    }

    public function testFirewallUsingUserKitCheckerDirectlyIsLeftAlone(): void
    {
        $container = $this->createContainer(['main' => AccountStatusUserChecker::class]);

        (new AccountStatusUserCheckerPass())->process($container);

        $this->assertSame(AccountStatusUserChecker::class, (string) $container->getAlias('security.user_checker.main'));
        $this->assertFalse($container->getDefinition(AccountStatusUserChecker::class)->hasTag('security.user_checker.main'));
    }

    public function testEveryFirewallIsWiredAndUnsecuredFirewallsAreSkipped(): void
    {
        $container = $this->createContainer(['main' => 'security.user_checker', 'admin' => 'security.user_checker']);
        $container->setParameter('security.firewalls', ['dev', 'main', 'admin', 42]);

        (new AccountStatusUserCheckerPass())->process($container);

        $checker = $container->getDefinition(AccountStatusUserChecker::class);
        $this->assertTrue($checker->hasTag('security.user_checker.main'));
        $this->assertTrue($checker->hasTag('security.user_checker.admin'));
        $this->assertFalse($checker->hasTag('security.user_checker.dev'));
        $this->assertSame('security.user_checker.chain.admin', (string) $container->getAlias('security.user_checker.admin'));
    }

    public function testNoopWhenCheckerIsDisabled(): void
    {
        $container = $this->createContainer(['main' => 'security.user_checker']);
        $container->removeDefinition(AccountStatusUserChecker::class);

        (new AccountStatusUserCheckerPass())->process($container);

        $this->assertSame('security.user_checker', (string) $container->getAlias('security.user_checker.main'));
    }

    public function testNoopWithoutSecurityBundle(): void
    {
        $container = new ContainerBuilder();
        $container->register(AccountStatusUserChecker::class, AccountStatusUserChecker::class);

        (new AccountStatusUserCheckerPass())->process($container);

        $this->assertSame([], $container->getDefinition(AccountStatusUserChecker::class)->getTags());
    }

    public function testNoopWhenFirewallsParameterIsNotAList(): void
    {
        $container = $this->createContainer(['main' => 'security.user_checker']);
        $container->setParameter('security.firewalls', 'main');

        (new AccountStatusUserCheckerPass())->process($container);

        $this->assertSame([], $container->getDefinition(AccountStatusUserChecker::class)->getTags());
    }

    /**
     * Mirrors what SecurityExtension registers per security-enabled firewall.
     *
     * @param array<string, string> $firewallCheckers firewall name => configured user_checker id
     */
    private function createContainer(array $firewallCheckers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(AccountStatusUserChecker::class, AccountStatusUserChecker::class);
        $container->register('security.user_checker', InMemoryUserChecker::class);
        $container->setParameter('security.firewalls', array_keys($firewallCheckers));

        foreach ($firewallCheckers as $firewall => $checkerId) {
            $container->register('security.user_checker.chain.' . $firewall, ChainUserChecker::class);
            $container->setAlias('security.user_checker.' . $firewall, new Alias($checkerId, false));
        }

        return $container;
    }
}
