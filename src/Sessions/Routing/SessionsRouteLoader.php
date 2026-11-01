<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Routing;

use Nowo\UserKitBundle\Sessions\Controller\SessionsController;
use Nowo\UserKitBundle\Sessions\Ui\SessionsPages;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function is_string;
use function rtrim;
use function str_starts_with;

/**
 * Route loader `nowo_user_kit_sessions` (REQ-SF-004 rule 7: the only way to expose the session pages). Reads the
 * `#[Route]` attributes of {@see SessionsController} and mounts "My sessions" under `web_ui.path_prefix` and the
 * administration routes under `web_ui.admin_path_prefix` (left out with `web_ui.admin: false`). Nothing is loaded
 * unless `sessions.enabled` and `sessions.web_ui.enabled` are true.
 *
 * ```yaml
 * # config/routes/nowo_user_kit_sessions.yaml
 * nowo_user_kit_sessions:
 *     resource: .
 *     type: nowo_user_kit_sessions
 * ```
 */
final class SessionsRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_user_kit_sessions';

    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $pathPrefix = '/account/sessions',
        private readonly string $adminPathPrefix = '/admin/user-sessions',
        private readonly bool $admin = true,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    /**
     * Builds the collection (stateless: safe to load more than once).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $collection = new RouteCollection();
        if (!$this->enabled) {
            return $collection;
        }

        foreach ((new ReflectionClass(SessionsController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                $declared = $attribute->newInstance();
                $name     = (string) $declared->name;
                $isAdmin  = str_starts_with($name, SessionsPages::ROUTE_ADMIN);
                if ($isAdmin && !$this->admin) {
                    continue;
                }
                $prefix = rtrim($isAdmin ? $this->adminPathPrefix : $this->pathPrefix, '/');
                $path   = is_string($declared->path) ? $declared->path : '';
                $collection->add($name, new Route(
                    $prefix . $path,
                    ['_controller' => SessionsController::class . '::' . $method->getName()],
                    $declared->requirements,
                    methods: $declared->methods,
                ));
            }
        }

        return $collection;
    }

    /**
     * Type `nowo_user_kit_sessions`.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
