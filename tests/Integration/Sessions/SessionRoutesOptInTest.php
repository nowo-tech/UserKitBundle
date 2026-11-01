<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\Sessions\Controller\SessionsController;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: a host whose routing is only `routing.controllers` (Symfony >= 7.3 default config/routes.yaml)
 * gets no bundle route; the session pages exist only through the `nowo_user_kit_sessions` loader import.
 */
final class SessionRoutesOptInTest extends SessionsKernelTestCase
{
    public function testRoutingControllersDoesNotMountTheBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers requires symfony/routing >= 7.3.');
        }
        $this->boot(routes: static function (RoutingConfigurator $routes): void {
            $routes->import('routing.controllers');
        });
        self::assertTrue($this->container()->has(SessionsController::class));

        foreach ($this->router()->getRouteCollection()->all() as $name => $route) {
            self::assertStringStartsNotWith('nowo_user_kit', $name);
            $controller = $route->getDefault('_controller');
            self::assertFalse(is_string($controller) && str_starts_with($controller, SessionsController::class), $name);
        }
    }

    public function testTheLoaderMountsThePagesUnderTheirPrefixes(): void
    {
        $this->boot(['web_ui' => ['path_prefix' => '/me/devices/', 'admin_path_prefix' => '/ops/sessions']]);
        $routes = $this->router()->getRouteCollection();

        self::assertSame('/me/devices', $routes->get('nowo_user_kit_sessions')?->getPath());
        self::assertSame('/me/devices/{id}/revoke', $routes->get('nowo_user_kit_sessions_revoke')?->getPath());
        self::assertSame('/me/devices/revoke-others', $routes->get('nowo_user_kit_sessions_revoke_others')?->getPath());
        self::assertSame('/ops/sessions/{profile}/{user}', $routes->get('nowo_user_kit_sessions_admin')?->getPath());
        self::assertSame(['POST'], $routes->get('nowo_user_kit_sessions_admin_revoke_all')?->getMethods());
    }

    public function testNoRouteWithoutThePages(): void
    {
        $this->boot(['web_ui' => ['enabled' => false]]);
        self::assertNull($this->router()->getRouteCollection()->get('nowo_user_kit_sessions'));
        self::assertFalse($this->container()->has(SessionsController::class));
    }

    private function router(): RouterInterface
    {
        $router = $this->container()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }
}
