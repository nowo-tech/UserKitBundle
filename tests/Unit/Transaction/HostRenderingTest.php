<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\UserKitBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;

use function json_decode;
use function sprintf;
use function str_starts_with;

/**
 * Audit TH UK-1 (1.21.0): on the application's own routes (UserKit's session listener and erasure run on any of them)
 * a UserKit lock conflict keeps the application's error rendering: its JSON shape and its error page, with the status,
 * `Retry-After` and the code added as the `X-Conflict-Code` header only. The application's error renderer here is a
 * problem+json controller for `/api/` and its own error page for the rest, behind the real ErrorListener.
 */
final class HostRenderingTest extends TestCase
{
    public function testAnApiRouteKeepsTheApplicationsJsonShape(): void
    {
        $response = $this->handle(Request::create('/api/orders', 'POST', server: ['HTTP_ACCEPT' => 'application/json']), new UserKitStorageFailure(1));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Conflict', 'status' => 409], json_decode((string) $response->getContent(), true), 'the application\'s keys only');
    }

    public function testAPageKeepsTheApplicationsErrorPage(): void
    {
        $response = $this->handle(Request::create('/account'), UserKitStorageFailure::afterCommit(new UserKitStorageFailure(1)));

        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'), 'CONFLICT_002: no blind retry');
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertStringContainsString('<body class="app-error"><h1>409 Conflict</h1>', (string) $response->getContent());
        self::assertStringNotContainsString('data-testid="concurrency-conflict"', (string) $response->getContent(), 'not the platform page');
    }

    public function testAt503AHostListenerStillSeesAServiceUnavailableHttpException(): void
    {
        $seen     = [];
        $response = $this->handle(Request::create('/api/orders', 'POST'), new UserKitStorageFailure(1), new ConflictResponsePolicy(503), static function (ExceptionEvent $event) use (&$seen): void {
            $seen[] = $event->getThrowable() instanceof ServiceUnavailableHttpException;
        });

        self::assertSame([true], $seen);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
    }

    /**
     * @param (callable(ExceptionEvent): void)|null $hostListener Runs between UserKit's subscriber and the ErrorListener
     */
    private function handle(Request $request, UserKitStorageFailure $failure, ?ConflictResponsePolicy $policy = null, ?callable $hostListener = null): Response
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StorageFailureSubscriber($policy));
        if ($hostListener !== null) {
            $dispatcher->addListener(KernelEvents::EXCEPTION, $hostListener, -100);
        }
        $error = static function (Request $request, FlattenException $exception): Response {
            if (str_starts_with($request->getPathInfo(), '/api/')) {
                return new JsonResponse(['type' => 'about:blank', 'title' => $exception->getStatusText(), 'status' => $exception->getStatusCode()], $exception->getStatusCode(), $exception->getHeaders() + ['Content-Type' => 'application/problem+json']);
            }

            return new Response(sprintf('<html><body class="app-error"><h1>%d %s</h1></body></html>', $exception->getStatusCode(), $exception->getStatusText()), $exception->getStatusCode(), $exception->getHeaders());
        };
        $dispatcher->addSubscriber(new ErrorListener($error));
        $resolver = new class($failure, $error) implements ControllerResolverInterface {
            /**
             * @param callable(Request, FlattenException): Response $error
             */
            public function __construct(private readonly UserKitStorageFailure $failure, private readonly mixed $error)
            {
            }

            public function getController(Request $request): callable
            {
                if ($request->attributes->has('exception')) {
                    return $this->error;
                }
                $failure = $this->failure;

                return static function () use ($failure): never {
                    throw $failure;
                };
            }
        };

        return (new HttpKernel($dispatcher, $resolver, new RequestStack(), new ArgumentResolver()))->handle($request);
    }
}
