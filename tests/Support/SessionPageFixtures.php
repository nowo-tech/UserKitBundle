<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\FormKitBundle\Form\FormTypeMap;
use Nowo\FormKitBundle\Form\Type\CsrfOnlyType;
use Nowo\UserKitBundle\DependencyInjection\SessionsLoader;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Ui\SessionsPages;
use stdClass;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Page contexts of the REQ-UI-003 contract test: view models from {@see SessionsPages} (the service the controller
 * uses) with real FormKit CSRF-only forms.
 */
final class SessionPageFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function own(bool $rows = true): array
    {
        $list = $rows ? [self::row('a', 'Firefox', 'Linux', '203.0.113.0/24'), self::row('b', 'Safari', 'iOS', 'h:0123456789abcdef'), self::row('c', '', '', null)] : [];

        return ['page' => self::pages()->ownPage($list, $rows ? self::hash('a') : null), 'current_hash' => $rows ? self::hash('a') : null];
    }

    /**
     * @return array<string, mixed>
     */
    public static function admin(bool $rows = true): array
    {
        $list = $rows ? [self::row('a', 'Edge', 'Windows', '2001:db8:85a3::/48'), self::row('b', 'Chrome', '', null)] : [];

        return ['page' => self::pages()->adminPage($list, 'default', '42', 'alice@example.com'), 'subject' => new stdClass()];
    }

    public static function row(string $key, string $browser, string $os, ?string $ip): UserSession
    {
        return new UserSession(
            str_pad($key, 32, '0'),
            'App\Entity\User',
            '42',
            self::hash($key),
            new DateTimeImmutable('2026-11-01 09:00:00'),
            new DateTimeImmutable('2026-11-01 10:00:00'),
            $browser,
            $os,
            $ip,
        );
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    private static function pages(): SessionsPages
    {
        $routes = new RouteCollection();
        foreach ([
            SessionsPages::ROUTE_REVOKE           => '/account/sessions/{id}/revoke',
            SessionsPages::ROUTE_REVOKE_OTHERS    => '/account/sessions/revoke-others',
            SessionsPages::ROUTE_ADMIN_REVOKE     => '/admin/user-sessions/{profile}/{user}/{id}/revoke',
            SessionsPages::ROUTE_ADMIN_REVOKE_ALL => '/admin/user-sessions/{profile}/{user}/revoke-all',
        ] as $name => $path) {
            $routes->add($name, new Route($path));
        }
        $urls    = new UrlGenerator($routes, new RequestContext());
        $profile = SessionsLoader::formKitProfile();
        unset($profile['alias']);
        /** @var array<string, array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>}> $profiles */
        $profiles = [SessionsLoader::FORM_KIT_PROFILE => $profile + ['by_form' => []]];
        $typeMap  = new FormTypeMap();
        $csrfOnly = new CsrfOnlyType(new FormOptionsMerger($profiles, SessionsLoader::FORM_KIT_PROFILE, new ConstraintDefinitionFactory()), $typeMap);
        $forms    = Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([$csrfOnly], []))
            ->addExtension(new CsrfExtension(new FixedCsrfTokenManager()))
            ->getFormFactory();

        return new SessionsPages($urls, new CsrfOnlyFormFactory($forms));
    }
}
