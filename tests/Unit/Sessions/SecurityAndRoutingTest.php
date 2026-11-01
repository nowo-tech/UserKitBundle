<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\Sessions\Doctrine\TablePrefixListener;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Routing\SessionsRouteLoader;
use Nowo\UserKitBundle\Sessions\Security\AccessGuard;
use Nowo\UserKitBundle\Sessions\Security\AllowAllSessionsAccessChecker;
use Nowo\UserKitBundle\Sessions\Security\RoleSessionsAccessChecker;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SecurityAndRoutingTest extends TestCase
{
    public function testRoleChecker(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_USER');
        $checker = new RoleSessionsAccessChecker($auth, ['ROLE_X', 'ROLE_USER'], ['ROLE_ADMIN']);

        self::assertTrue($checker->canAccessOwn(new stdClass()));
        self::assertFalse($checker->canAccessOwn(null));
        self::assertFalse($checker->canManage(new stdClass(), new stdClass()));
        self::assertFalse($checker->canManage(null, new stdClass()));
    }

    public function testAllowAllChecker(): void
    {
        $checker = new AllowAllSessionsAccessChecker();
        self::assertTrue($checker->canAccessOwn(new stdClass()));
        self::assertFalse($checker->canAccessOwn(null));
        self::assertTrue($checker->canManage(null, new stdClass()));
    }

    public function testGuardTrustsTheTokenOnlyBehindAFirewall(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        $stack = new RequestStack();
        $guard = new AccessGuard(new AllowAllSessionsAccessChecker(), $tokens, $stack);
        self::assertNull($guard->user());
        self::assertFalse($guard->canAccessOwn());
        self::assertTrue($guard->canManage(new stdClass()));

        $request = new Request();
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.main');
        $stack->push($request);
        self::assertInstanceOf(InMemoryUser::class, $guard->user());
        self::assertTrue($guard->canAccessOwn());

        $this->expectException(AccessDeniedException::class);
        $guard->deny('no');
    }

    public function testGuardDeniesWith403OutsideAFirewall(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        (new AccessGuard(new AllowAllSessionsAccessChecker()))->deny('no');
    }

    public function testRouteLoader(): void
    {
        self::assertCount(0, (new SessionsRouteLoader())->load('.'));
        $routes = (new SessionsRouteLoader(true, '/me/sessions', '/ops/sessions', false))->load('.');
        self::assertSame('/me/sessions', $routes->get('nowo_user_kit_sessions')?->getPath());
        self::assertNull($routes->get('nowo_user_kit_sessions_admin'));
        self::assertTrue((new SessionsRouteLoader())->supports('.', SessionsRouteLoader::TYPE));
        self::assertFalse((new SessionsRouteLoader())->supports('.', 'attribute'));
    }

    public function testTablePrefixListener(): void
    {
        $em       = $this->createMock(EntityManagerInterface::class);
        $metadata = new ClassMetadata(UserSession::class);
        $metadata->setPrimaryTable(['name' => 'user_kit_session', 'indexes' => ['idx_a' => ['columns' => ['a']]], 'uniqueConstraints' => ['uniq_b' => ['columns' => ['b']]]]);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $em));
        self::assertSame('app_user_kit_session', $metadata->getTableName());
        self::assertArrayHasKey('app_idx_a', $metadata->table['indexes'] ?? []);
        self::assertArrayHasKey('app_uniq_b', $metadata->table['uniqueConstraints'] ?? []);

        $bare = new ClassMetadata(UserSession::class);
        $bare->setPrimaryTable(['name' => 'user_kit_session']);
        (new TablePrefixListener('x_'))->loadClassMetadata(new LoadClassMetadataEventArgs($bare, $em));
        self::assertSame('x_user_kit_session', $bare->getTableName());

        $host = new ClassMetadata(stdClass::class);
        $host->setPrimaryTable(['name' => 'host']);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($host, $em));
        self::assertSame('host', $host->getTableName());

        $empty = new ClassMetadata(UserSession::class);
        $empty->setPrimaryTable(['name' => 'user_kit_session']);
        (new TablePrefixListener())->loadClassMetadata(new LoadClassMetadataEventArgs($empty, $em));
        self::assertSame('user_kit_session', $empty->getTableName());
    }

    public function testStoreFailsWithoutAnEntityManager(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn(null);
        $this->expectException(LogicException::class);
        (new UserSessionStore($doctrine))->table();
    }

    public function testUserResolverWithoutDoctrine(): void
    {
        $resolver = new SessionUserResolver();
        self::assertSame('alice', $resolver->resolve(new InMemoryUser('alice', null))?->id);
        self::assertNull($resolver->resolve(new InMemoryUser(str_repeat('a', 200), null)));
        self::assertNull($resolver->resolve(new stdClass()));
    }
}
