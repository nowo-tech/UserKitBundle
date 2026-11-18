<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle;
use Nowo\UserKitBundle\DependencyInjection\Compiler\ConflictResponsePass;
use Nowo\UserKitBundle\DependencyInjection\Configuration;
use Nowo\UserKitBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\UserKitBundle\NowoUserKitBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_filter;
use function sys_get_temp_dir;

/**
 * 1.21.0 (REQ-RUNTIME-003 rule 6): the conflict answer of UserKit follows nowo-tech/transaction-doctrine's settings
 * when its bundle is registered, else 409; `nowo_user_kit.conflict_response.status` overrides it (503 for the 1.20
 * status).
 */
final class ConflictResponsePassTest extends TestCase
{
    public function testTheConfigurationDefaultsToTheSharedPolicyAndThePlatformAnswer(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame(['status' => null, 'retry_after' => null, 'platform_answer' => null], $config['conflict_response']);

        $config = (new Processor())->processConfiguration(new Configuration(), [['conflict_response' => ['status' => 503, 'retry_after' => 5, 'platform_answer' => true]]]);
        self::assertSame(['status' => 503, 'retry_after' => 5, 'platform_answer' => true], $config['conflict_response']);

        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['conflict_response' => ['status' => 500]]]);
    }

    public function testTheBundleRegistersThePass(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitBundle())->build($container);

        self::assertCount(1, array_filter($container->getCompilerPassConfig()->getBeforeOptimizationPasses(), static fn (object $pass): bool => $pass instanceof ConflictResponsePass));
    }

    public function testWithoutTheSubscriberNothingIsWired(): void
    {
        $container = new ContainerBuilder();
        (new ConflictResponsePass())->process($container);

        self::assertFalse($container->hasDefinition(ConflictResponsePass::OWN_POLICY));

        $container->setDefinition(StorageFailureSubscriber::class, new Definition(StorageFailureSubscriber::class));
        (new ConflictResponsePass())->process($container);
        self::assertSame([409, 1], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments(), 'defaults without the parameter');
    }

    public function testWithoutTransactionDoctrinesBundleTheApplicationRendersA409(): void
    {
        $container = $this->container(null);
        (new ConflictResponsePass())->process($container);

        self::assertSame([409, 1], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments());
        $subscriber = $container->getDefinition(StorageFailureSubscriber::class);
        self::assertEquals(new Reference(ConflictResponsePass::OWN_POLICY), $subscriber->getArgument('$policy'));
        $responder = $subscriber->getArgument('$responder');
        self::assertInstanceOf(Definition::class, $responder);
        self::assertSame(ConcurrencyConflictResponder::class, $responder->getClass());
        self::assertSame([], $responder->getArgument(2), 'no formatters');
        self::assertNull($responder->getArgument(3), 'no template without the bundle');
        self::assertNull($responder->getArgument(4), 'no translations without the bundle');
        self::assertFalse($subscriber->getArgument('$platformAnswer'), 'the application\'s error rendering');

        $container->compile();
        $answer = $container->get(StorageFailureSubscriber::class);
        self::assertInstanceOf(StorageFailureSubscriber::class, $answer);
    }

    public function testTheSharedResponderIsUsedWhenTheBundleAnswersConflicts(): void
    {
        $container = $this->container(null, policy: true, responder: true);
        (new ConflictResponsePass())->process($container);

        $subscriber = $container->getDefinition(StorageFailureSubscriber::class);
        self::assertEquals(new Reference(ConflictResponsePass::SHARED_POLICY), $subscriber->getArgument('$policy'));
        self::assertEquals(new Reference(ConflictResponsePass::SHARED_RESPONDER), $subscriber->getArgument('$responder'));
        self::assertFalse($subscriber->getArgument('$platformAnswer'), 'registering the bundle alone keeps the application\'s rendering');
        self::assertFalse($container->hasDefinition(ConflictResponsePass::OWN_POLICY));
    }

    public function testTheSharedPolicyWithTheBundlesTemplateWhenItsResponderIsOff(): void
    {
        $container = $this->container(null, policy: true);
        (new ConflictResponsePass())->process($container);

        $subscriber = $container->getDefinition(StorageFailureSubscriber::class);
        self::assertEquals(new Reference(ConflictResponsePass::SHARED_POLICY), $subscriber->getArgument('$policy'));
        $responder = $subscriber->getArgument('$responder');
        self::assertInstanceOf(Definition::class, $responder);
        self::assertInstanceOf(TaggedIteratorArgument::class, $responder->getArgument(2));
        self::assertEquals(new Reference('twig', ContainerBuilder::NULL_ON_INVALID_REFERENCE), $responder->getArgument(3));
        self::assertEquals(new Reference('translator', ContainerBuilder::NULL_ON_INVALID_REFERENCE), $responder->getArgument(4));
        self::assertFalse($subscriber->getArgument('$platformAnswer'), 'its conflict_response is off: the application\'s rendering');
    }

    public function testThePlatformAnswerIsOptIn(): void
    {
        $container = $this->container(null, platformAnswer: true);
        (new ConflictResponsePass())->process($container);

        self::assertTrue($container->getDefinition(StorageFailureSubscriber::class)->getArgument('$platformAnswer'));
    }

    public function testRetryAfterOverridesTheBundlesAndKeepsItsStatus(): void
    {
        $container = $this->container(null, policy: true, responder: true, retryAfter: 9);
        $container->setParameter('td.status', 503);
        $container->getDefinition(ConflictResponsePass::SHARED_POLICY)->setArguments(['%td.status%', 2]);
        (new ConflictResponsePass())->process($container);

        self::assertSame([503, 9], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments());
        $subscriber = $container->getDefinition(StorageFailureSubscriber::class);
        self::assertEquals(new Reference(ConflictResponsePass::OWN_POLICY), $subscriber->getArgument('$policy'));
        self::assertInstanceOf(Definition::class, $subscriber->getArgument('$responder'), 'a responder with UserKit\'s policy');
        self::assertFalse($subscriber->getArgument('$platformAnswer'));
    }

    public function testASharedPolicyServiceWithoutADefinitionGivesTheDefaults(): void
    {
        $container = $this->container(503);
        $container->setDefinition('td.policy', new Definition(ConflictResponsePolicy::class));
        $container->setAlias(ConflictResponsePass::SHARED_POLICY, 'td.policy');
        (new ConflictResponsePass())->process($container);

        self::assertSame([503, 1], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments());
    }

    public function testAnExplicitStatusWinsOverTheBundle(): void
    {
        $container = $this->container(503, policy: true, responder: true);
        (new ConflictResponsePass())->process($container);

        self::assertSame([503, 1], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments(), 'the bundle\'s policy has no arguments: defaults');
        $subscriber = $container->getDefinition(StorageFailureSubscriber::class);
        self::assertEquals(new Reference(ConflictResponsePass::OWN_POLICY), $subscriber->getArgument('$policy'));
        self::assertInstanceOf(Definition::class, $subscriber->getArgument('$responder'));
        self::assertFalse($subscriber->getArgument('$platformAnswer'));
    }

    /**
     * Audit TH: the rendering for each state of NowoTransactionDoctrineBundle and each value of `platform_answer`.
     * Explicit true / false always win; null (auto) gives the platform answer only with `map_transient_failures`.
     *
     * @return iterable<string, array{array<string, mixed>|null, bool|null, bool}>
     */
    public static function renderings(): iterable
    {
        $states = [
            'no TD bundle'              => [null, false],
            'TD bundle, no config'      => [[], false],
            'TD conflict_response off'  => [['conflict_response' => ['enabled' => false]], false],
            'TD map_transient_failures' => [['conflict_response' => ['map_transient_failures' => true]], true],
        ];
        foreach ($states as $name => [$td, $auto]) {
            yield $name . ', platform_answer true' => [$td, true, true];
            yield $name . ', platform_answer false' => [$td, false, false];
            yield $name . ', platform_answer null' => [$td, null, $auto];
        }
    }

    /**
     * @param array<string, mixed>|null $transactionDoctrine nowo_transaction_doctrine configuration; null = not registered
     */
    #[DataProvider('renderings')]
    public function testTheRenderingFollowsPlatformAnswerAndOnlyMapTransientFailuresInAutoMode(?array $transactionDoctrine, ?bool $platformAnswer, bool $expected): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        if ($transactionDoctrine !== null) {
            $extension = (new NowoTransactionDoctrineBundle())->getContainerExtension();
            self::assertNotNull($extension);
            $extension->load([$transactionDoctrine], $container);
        }
        $container->setParameter(ConflictResponsePass::PARAMETER, ['status' => null, 'retry_after' => null, 'platform_answer' => $platformAnswer]);
        $container->setDefinition(StorageFailureSubscriber::class, (new Definition(StorageFailureSubscriber::class))->setPublic(true));
        (new ConflictResponsePass())->process($container);

        $subscriber = $container->getDefinition(StorageFailureSubscriber::class);
        self::assertSame($expected, $subscriber->getArgument('$platformAnswer'));
        $enabled = $transactionDoctrine !== null && ($transactionDoctrine['conflict_response']['enabled'] ?? true);
        if ($enabled) {
            self::assertEquals(new Reference(ConflictResponsePass::SHARED_RESPONDER), $subscriber->getArgument('$responder'), 'the bundle\'s own responder when it answers conflicts');
        } else {
            self::assertInstanceOf(Definition::class, $subscriber->getArgument('$responder'));
        }
        self::assertEquals(new Reference($transactionDoctrine === null ? ConflictResponsePass::OWN_POLICY : ConflictResponsePass::SHARED_POLICY), $subscriber->getArgument('$policy'));
    }

    private function container(?int $status, bool $policy = false, bool $responder = false, ?bool $platformAnswer = null, ?int $retryAfter = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(ConflictResponsePass::PARAMETER, ['status' => $status, 'retry_after' => $retryAfter, 'platform_answer' => $platformAnswer]);
        $container->setDefinition(StorageFailureSubscriber::class, (new Definition(StorageFailureSubscriber::class))->setPublic(true));
        if ($policy) {
            $container->setDefinition(ConflictResponsePass::SHARED_POLICY, new Definition(ConflictResponsePolicy::class));
        }
        if ($responder) {
            $container->setDefinition(ConflictResponsePass::SHARED_RESPONDER, new Definition(ConcurrencyConflictResponder::class, [new Reference(ConflictResponsePass::SHARED_POLICY)]));
        }

        return $container;
    }
}
