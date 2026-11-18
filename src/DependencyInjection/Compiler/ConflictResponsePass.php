<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection\Compiler;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\UserKitBundle\EventSubscriber\StorageFailureSubscriber;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function is_int;

/**
 * 1.21.0 (REQ-RUNTIME-003 rule 6): wires the conflict answer of {@see StorageFailureSubscriber} once every extension is
 * loaded, so one setting drives the whole application.
 *
 * - **Policy** (status and `Retry-After`): `nowo_user_kit.conflict_response.status` and `retry_after`; each one left
 *   out (null) comes from NowoTransactionDoctrineBundle's `conflict_response` when it is registered, else 409 and 1.
 * - **Rendering**: the application's own error rendering (an HTTP exception with the status, `Retry-After` and
 *   `X-Conflict-Code`), unless `conflict_response.platform_answer: true` or NowoTransactionDoctrineBundle's
 *   `conflict_response` is enabled. The platform answer is the bundle's own responder when UserKit's policy follows the
 *   bundle's (template, translations, JSON error key and path prefixes, the application's formatters), else a
 *   responder with UserKit's policy (the bundle's template and translations when it is registered, plain English
 *   otherwise).
 */
final class ConflictResponsePass implements CompilerPassInterface
{
    /** Parameter set by the extension: `conflict_response` (status, retry_after, platform_answer). */
    public const PARAMETER = 'nowo_user_kit.conflict_response';

    /** Services of nowo-tech/transaction-doctrine's Symfony bundle. */
    public const SHARED_POLICY    = 'nowo_transaction_doctrine.conflict_response_policy';
    public const SHARED_RESPONDER = 'nowo_transaction_doctrine.conflict_responder';

    /** UserKit's own policy, when `status` or `retry_after` is set or the bundle is not registered. */
    public const OWN_POLICY = 'nowo_user_kit.conflict_response_policy';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(StorageFailureSubscriber::class)) {
            return;
        }
        /** @var array{status: int|null, retry_after: int|null, platform_answer: bool} $config */
        $config = ($container->hasParameter(self::PARAMETER) ? $container->getParameter(self::PARAMETER) : []) + ['status' => null, 'retry_after' => null, 'platform_answer' => false];
        $shared = $container->has(self::SHARED_POLICY);
        $own    = is_int($config['status']) || is_int($config['retry_after']) || !$shared;

        if ($own) {
            $container->setDefinition(self::OWN_POLICY, new Definition(ConflictResponsePolicy::class, [
                $config['status'] ?? $this->shared($container, 0) ?? ConflictResponsePolicy::STATUS_CONFLICT,
                $config['retry_after'] ?? $this->shared($container, 1) ?? ConflictResponsePolicy::DEFAULT_RETRY_AFTER,
            ]));
            $policy = new Reference(self::OWN_POLICY);
        } else {
            $policy = new Reference(self::SHARED_POLICY);
        }

        $sharedResponder = $container->has(self::SHARED_RESPONDER);
        if (!$own && $sharedResponder) {
            $responder = new Reference(self::SHARED_RESPONDER);
        } else {
            // The bundle's template and translations exist only when it is registered; else the plain English answer.
            $responder = new Definition(ConcurrencyConflictResponder::class, [
                $policy,
                [],
                $shared ? new TaggedIteratorArgument(ConflictResponseFormatterInterface::TAG) : [],
                $shared ? new Reference('twig', ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
                $shared ? new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
        }

        $container->getDefinition(StorageFailureSubscriber::class)
            ->setArgument('$policy', $policy)
            ->setArgument('$responder', $responder)
            ->setArgument('$platformAnswer', $config['platform_answer'] || $sharedResponder);
    }

    /**
     * An argument of the bundle's policy (0: status, 1: Retry-After), when it is registered with an integer there.
     */
    private function shared(ContainerBuilder $container, int $index): ?int
    {
        if (!$container->hasDefinition(self::SHARED_POLICY)) {
            return null;
        }
        $arguments = $container->getDefinition(self::SHARED_POLICY)->getArguments();
        $value     = isset($arguments[$index]) ? $container->getParameterBag()->resolveValue($arguments[$index]) : null;

        return is_int($value) ? $value : null;
    }
}
