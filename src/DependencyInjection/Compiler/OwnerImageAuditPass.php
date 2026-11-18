<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection\Compiler;

use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\UserKitBundle\OwnerImage\Audit\OwnerImageAuditListener;
use Nowo\UserKitBundle\OwnerImage\OwnerImageChangedEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjectInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function interface_exists;
use function is_a;
use function is_string;

/**
 * 1.20.0: registers {@see OwnerImageAuditListener} when `owner_images.audit` is enabled, once every extension is
 * loaded (AuditKit's action log services exist only when `nowo_audit_kit.action_log` is enabled). Fails the
 * compilation when AuditKit (1.15+: id-based recording and flush_mode) or its action log is missing, and when an action log of user
 * relations would get subject ids that are not the users' identifiers (a user class with
 * {@see OwnerImageSubjectInterface} and no `owner_images.audit.user_id_field`), and unless AuditKit's
 * `action_log.flush_mode` is `isolated` (audit TC-M1).
 */
final class OwnerImageAuditPass implements CompilerPassInterface
{
    /** Parameter set by the loader when the bridge is enabled: the actions per kind and `user_id_field`. */
    public const PARAMETER = 'nowo_user_kit.owner_images.audit';

    /** AuditKit's parameter of the action log flush mode. */
    public const FLUSH_MODE_PARAMETER = 'nowo_audit_kit.action_log.flush_mode';

    /** The only flush mode the bridge accepts. */
    public const FLUSH_ISOLATED = 'isolated';

    /** AuditKit's parameter of the action log entity class. */
    public const LOG_CLASS_PARAMETER = 'nowo_audit_kit.action_log.entity_class';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER)) {
            return;
        }
        /** @var array{actions: array<string, array{uploaded: string, replaced: string, removed: string}>, user_id_field: string|null} $audit */
        $audit = $container->getParameter(self::PARAMETER);
        if (!interface_exists(ActionLogIdRecorderInterface::class) || !$container->has(ActionLogIdRecorderInterface::class)) {
            throw new InvalidConfigurationException('nowo_user_kit.owner_images.audit needs nowo-tech/audit-kit-bundle 1.15+ (composer require nowo-tech/audit-kit-bundle) with its action log enabled (nowo_audit_kit.action_log.entity_class).');
        }
        $flushMode = $container->hasParameter(self::FLUSH_MODE_PARAMETER) ? $container->getParameterBag()->resolveValue($container->getParameter(self::FLUSH_MODE_PARAMETER)) : null;
        if ($flushMode !== self::FLUSH_ISOLATED) {
            // Audit TC-M1: in "host" mode recordIdsAndFlush() flushes the application's entity manager (any half-edited
            // entity) and closes it when the row fails.
            throw new InvalidConfigurationException('nowo_user_kit.owner_images.audit needs nowo_audit_kit.action_log.flush_mode: isolated (the "host" mode would flush the application\'s pending changes with every audit row).');
        }
        $logClass    = $container->hasParameter(self::LOG_CLASS_PARAMETER) ? $container->getParameterBag()->resolveValue($container->getParameter(self::LOG_CLASS_PARAMETER)) : null;
        $relations   = is_string($logClass) && !is_a($logClass, AbstractIdActionLog::class, true);
        $userIdField = $audit['user_id_field'];
        if ($relations && $userIdField === null && $this->customSubjectIds($container)) {
            throw new InvalidConfigurationException('nowo_user_kit.owner_images.audit: the action log references users (AbstractActionLog) but the owner image subject ids come from OwnerImageSubjectInterface: set owner_images.audit.user_id_field to the user field that holds them.');
        }

        $container->setDefinition(OwnerImageAuditListener::class, (new Definition(OwnerImageAuditListener::class, [
            new Reference(ActionLogIdRecorderInterface::class),
            new Reference(OwnerImageKinds::class),
            $audit['actions'],
            new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $relations ? $userIdField : null,
            $relations ? $logClass : null,
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('kernel.event_listener', ['event' => OwnerImageChangedEvent::class]));
    }

    /**
     * Whether users get their subject id from {@see OwnerImageSubjectInterface} (or no user class is set, so only such
     * users have one) rather than from their identifier.
     */
    private function customSubjectIds(ContainerBuilder $container): bool
    {
        $userClass = $container->hasDefinition(OwnerImageSubjects::class) ? $container->getDefinition(OwnerImageSubjects::class)->getArgument(1) : null;

        return !is_string($userClass) || is_a($userClass, OwnerImageSubjectInterface::class, true);
    }
}
