<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function in_array;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

/**
 * Kernel of the session registry tests: Framework (mock file sessions, form CSRF, translator), Security (stateful
 * `main` firewall with form_login, remember_me, logout and switch_user; stateless `api` firewall with http_basic),
 * Doctrine on a SQLite file, Twig, FormKit, UiKit and UserKit with `sessions` enabled. Configuration and routes are
 * injected per test.
 */
final class SessionsTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $userKit `nowo_user_kit` configuration
     * @param Closure(RoutingConfigurator): void|null $routes routes (null = the bundle loader + the test pages)
     * @param list<string> $without bundles / features to leave out (twig)
     * @param array{remember_me?: array<string, mixed>, back_firewall?: bool, catch_all?: bool, database_url?: string|null, registry_manager?: bool} $options extra `remember_me` options of the
     *                                                                                                                                                        main firewall; a second stateful firewall with its own context under /back; another database than SQLite; the registry on its own connection and entity manager (`registry`)
     */
    public function __construct(
        private readonly string $id,
        private readonly array $userKit,
        private readonly ?Closure $routes = null,
        private readonly array $without = [],
        bool $debug = false,
        private readonly ?Closure $services = null,
        private readonly array $options = [],
    ) {
        parent::__construct('test', $debug);
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-user-kit-sessions-tests';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        if (!in_array('twig', $this->without, true)) {
            yield new TwigBundle();
            yield new TwigExtraBundle();
            yield new NowoFormKitBundle();
            yield new NowoUiKitBundle();
        }
        yield new NowoUserKitBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    /**
     * Test page: the signed-in user identifier (or "anonymous") and the flashes.
     */
    public function page(Request $request): Response
    {
        $tokens  = $this->service('security.token_storage');
        $user    = $tokens instanceof TokenStorageInterface ? $tokens->getToken()?->getUser() : null;
        $session = $request->hasSession() ? $request->getSession() : null;
        $flashes = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->all() : [];

        return new Response(($user?->getUserIdentifier() ?? 'anonymous') . "\n" . json_encode($flashes, JSON_THROW_ON_ERROR));
    }

    /**
     * `_stateless` test page: the signed-in user identifier only (never touches the session itself).
     */
    public function statelessPage(): Response
    {
        $tokens = $this->service('security.token_storage');
        $user   = $tokens instanceof TokenStorageInterface ? $tokens->getToken()?->getUser() : null;

        return new Response($user?->getUserIdentifier() ?? 'anonymous');
    }

    /**
     * Changes the signed-in member's password (the password-change listener keeps this session).
     */
    public function password(): Response
    {
        $tokens = $this->service('security.token_storage');
        $member = $tokens instanceof TokenStorageInterface ? $tokens->getToken()?->getUser() : null;
        $em     = $this->service('doctrine.orm.entity_manager');
        if ($member instanceof Member && $em instanceof EntityManagerInterface) {
            $member->setPassword('changed');
            $em->flush();
        }

        return new Response('changed');
    }

    /**
     * Signs out the signed-in member's session `?id=` (public id) and voids the member's remember-me cookies; the
     * current session is the kept one ("sign out this device" with `invalidateRememberMe`).
     */
    public function revokeRememberMe(Request $request): Response
    {
        $tokens   = $this->service('security.token_storage');
        $member   = $tokens instanceof TokenStorageInterface ? $tokens->getToken()?->getUser() : null;
        $sessions = $this->service(UserSessionManager::class);
        if ($member instanceof Member && $sessions instanceof UserSessionManager) {
            $sessions->revoke($member, (string) $request->query->get('id'), invalidateRememberMe: true);
        }

        return new Response('revoked');
    }

    private function service(string $id): ?object
    {
        $container = $this->getContainer()->get('test.service_container');

        return $container instanceof ContainerInterface ? $container->get($id) : null;
    }

    /**
     * Login page: the last authentication error.
     */
    public function login(Request $request): Response
    {
        $error = $request->hasSession() ? $request->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR) : null;

        return new Response('login: ' . ($error instanceof AuthenticationException ? $error->getMessageKey() : ''));
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'test-secret',
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'csrf_protection'       => true,
            'form'                  => ['csrf_protection' => true],
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'translator'            => ['default_path' => '%kernel.project_dir%/translations', 'fallbacks' => ['en']],
            'router'                => ['utf8' => true],
        ]);
        if (!in_array('twig', $this->without, true)) {
            $container->extension('twig', ['strict_variables' => true]);
        }
        $mappings = ['SessionsTest' => [
            'type'      => 'attribute',
            'is_bundle' => false,
            'dir'       => __DIR__,
            'prefix'    => __NAMESPACE__,
        ]];
        $container->extension('doctrine', ($this->options['registry_manager'] ?? false) ? [
            'dbal' => ['default_connection' => 'default', 'connections' => [
                'default'  => ['url' => 'sqlite:///%kernel.cache_dir%/users.db'],
                'registry' => ['url' => 'sqlite:///%kernel.cache_dir%/registry.db'],
            ]],
            'orm' => ['default_entity_manager' => 'default', 'entity_managers' => [
                'default'  => ['connection' => 'default', 'mappings' => $mappings],
                'registry' => ['connection' => 'registry'],
            ]],
        ] : [
            'dbal' => ['url' => $this->options['database_url'] ?? 'sqlite:///%kernel.cache_dir%/registry.db'],
            'orm'  => ['mappings' => $mappings],
        ]);
        $container->extension('security', [
            'password_hashers' => [Member::class => 'plaintext'],
            'providers'        => ['members' => ['entity' => ['class' => Member::class, 'property' => 'email']]],
            'role_hierarchy'   => ['ROLE_ADMIN' => ['ROLE_USER', 'ROLE_ALLOWED_TO_SWITCH']],
            'firewalls'        => [
                'api' => ['pattern' => '^/api', 'stateless' => true, 'http_basic' => true, 'provider' => 'members'],
            ] + (($this->options['back_firewall'] ?? false) ? ['back' => [
                'pattern'    => '^/back',
                'context'    => 'back',
                'lazy'       => true,
                'provider'   => 'members',
                'form_login' => ['login_path' => '/back/login', 'check_path' => '/back/login', 'enable_csrf' => false, 'default_target_path' => '/back/page'],
            ]] : []) + [
                'main' => [
                    'lazy'        => true,
                    'provider'    => 'members',
                    'form_login'  => ['login_path' => '/login', 'check_path' => '/login', 'enable_csrf' => false, 'default_target_path' => '/page'],
                    'remember_me' => ['secret' => 'test-secret'] + ($this->options['remember_me'] ?? []),
                    'logout'      => ['path' => '/logout'],
                    'switch_user' => true,
                ],
            ],
            'access_control' => [
                ['path' => '^/login', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/(account|admin|page|api)', 'roles' => 'ROLE_USER'],
            ],
        ]);
        $container->extension('nowo_user_kit', $this->userKit + ['user_class' => Member::class]);
        $container->services()->set('logger', NullLogger::class);
        $container->services()->set(MockClock::class)->args(['2026-11-01 10:00:00'])->public();
        $container->services()->alias(ClockInterface::class, MockClock::class)->public();
        $container->services()->alias('clock', MockClock::class)->public();
        if ($this->services instanceof Closure) {
            ($this->services)($container);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->routes instanceof Closure) {
            ($this->routes)($routes);

            return;
        }
        $routes->import('.', 'nowo_user_kit_sessions');
        $routes->add('page', '/page')->controller('kernel::page');
        $routes->add('api_page', '/api/page')->controller('kernel::page');
        $routes->add('login', '/login')->controller('kernel::login');
        $routes->add('password', '/page/password')->controller('kernel::password');
        $routes->add('revoke_remember_me', '/page/revoke-remember-me')->controller('kernel::revokeRememberMe');
        $routes->add('page_stateless', '/page/stateless')->controller('kernel::statelessPage')->defaults(['_stateless' => true]);
        $routes->add('back_page', '/back/page')->controller('kernel::page');
        $routes->add('back_login', '/back/login')->controller('kernel::login');
        if ($this->options['catch_all'] ?? false) {
            // A host catch-all route (CMS / SPA fallback): `//host/x` reaches the controller.
            $routes->add('catch_all', '/{path}')->controller('kernel::statelessPage')->requirements(['path' => '.+']);
        }
        $routes->add('logout', '/logout');
    }
}
