<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\Twig\OwnerImageExtension;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

use function array_keys;
use function is_string;
use function preg_match;

/**
 * `nowo_user_kit.owner_images` (1.18): profile pictures, organization logos and any other square image of a subject.
 */
final class OwnerImagesConfiguration
{
    /** Default kinds. */
    public const DEFAULT_KINDS = [
        'avatar' => ['subject' => OwnerImageKind::SUBJECT_USER, 'read' => OwnerImageKind::READ_AUTHENTICATED, 'shape' => OwnerImageKind::SHAPE_CIRCLE],
    ];

    /** `owner_images.pointer_store` value of the column pointer store (1.20). */
    public const POINTER_STORE_COLUMN = 'column';

    /** A Doctrine field name. */
    public const FIELD_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** An action log action once `{kind}` is replaced (AuditKit's `action` column holds 48 characters). */
    public const ACTION_PATTERN = '/^[A-Za-z0-9_.:\\-]{1,48}$/D';

    /** Default actions of the AuditKit bridge (1.20). */
    public const DEFAULT_AUDIT_ACTIONS = ['uploaded' => 'owner_image.uploaded', 'replaced' => 'owner_image.replaced', 'removed' => 'owner_image.removed'];

    /** A fully qualified class name. */
    public const CLASS_PATTERN = '/^\\\\?[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*(?:\\\\[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*)*$/';

    public function node(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('owner_images'))->getRootNode();
        $node
            ->info('Owner images (1.18): one square image per subject and kind — profile pictures, organization logos. Off by default; the default storage needs nowo-tech/storage-kit-bundle 1.6+.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->arrayNode('kinds')
                    ->info('Kinds by name (1-16 characters [a-z0-9_], also the StorageKit context type). subject: user (a user\'s own image) | other (your access service decides); read: public | authenticated | host; shape: circle | square (crop frame).')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('subject')->values([OwnerImageKind::SUBJECT_USER, OwnerImageKind::SUBJECT_OTHER])->defaultValue(OwnerImageKind::SUBJECT_OTHER)->end()
                            ->enumNode('read')->values([OwnerImageKind::READ_PUBLIC, OwnerImageKind::READ_AUTHENTICATED, OwnerImageKind::READ_HOST])->defaultValue(OwnerImageKind::READ_AUTHENTICATED)->end()
                            ->enumNode('shape')->values([OwnerImageKind::SHAPE_CIRCLE, OwnerImageKind::SHAPE_SQUARE])->defaultValue(OwnerImageKind::SHAPE_SQUARE)->end()
                            ->arrayNode('pointer')
                                ->info('1.20, with pointer_store: column: the column of the owner\'s row that holds this kind\'s image id. entity: the root Doctrine entity; field: its field for the file id; subject_field: its field that holds the subject id (null: the identifier).')
                                ->children()
                                    ->scalarNode('entity')
                                        ->isRequired()
                                        ->validate()
                                            ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match(self::CLASS_PATTERN, $v) !== 1)
                                            ->thenInvalid('owner_images.kinds.*.pointer.entity must be a fully qualified class name.')
                                        ->end()
                                    ->end()
                                    ->scalarNode('field')
                                        ->isRequired()
                                        ->validate()
                                            ->ifTrue(static fn (mixed $v): bool => !self::isField($v))
                                            ->thenInvalid('owner_images.kinds.*.pointer.field must be a field name.')
                                        ->end()
                                    ->end()
                                    ->scalarNode('subject_field')
                                        ->defaultNull()
                                        ->validate()
                                            ->ifTrue(static fn (mixed $v): bool => $v !== null && !self::isField($v))
                                            ->thenInvalid('owner_images.kinds.*.pointer.subject_field must be a field name.')
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue(self::DEFAULT_KINDS)
                    ->validate()
                        ->ifTrue(static function (array $kinds): bool {
                            foreach (array_keys($kinds) as $name) {
                                if (preg_match(OwnerImageKind::NAME_PATTERN, (string) $name) !== 1) {
                                    return true;
                                }
                            }

                            return $kinds === [];
                        })
                        ->thenInvalid('nowo_user_kit.owner_images.kinds needs at least one kind, each named with 1-16 characters [a-z0-9_].')
                    ->end()
                ->end()
                ->scalarNode('user_class')
                    ->info('The user class (or interface) whose Doctrine identifier (else user identifier) is the subject id of its users (null: the user_class of the only profile; with several profiles none, so set it). Users of any other class get no subject id unless they implement OwnerImageSubjectInterface, so two user classes sharing an id never share an image.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || preg_match(self::CLASS_PATTERN, $v) !== 1))
                        ->thenInvalid('nowo_user_kit.owner_images.user_class must be a fully qualified class or interface name.')
                    ->end()
                ->end()
                ->scalarNode('avatar_kind')
                    ->info('Kind shown by the Twig function user_avatar() (a "user" kind; a name that is not configured shows the initials only).')
                    ->defaultValue('avatar')
                ->end()
                ->scalarNode('access')
                    ->info('Service id of your OwnerImageAccessInterface (null: DefaultOwnerImageAccess — users change their own image, "other" kinds nobody).')
                    ->defaultNull()
                ->end()
                ->scalarNode('pointer_store')
                    ->info('Service id of your OwnerImagePointerStoreInterface (null: the bundle table user_kit_owner_image; 1.20 "column": a column of the owner\'s row, set kinds.*.pointer).')
                    ->defaultNull()
                ->end()
                ->arrayNode('audit')
                    ->info('1.20: AuditKit action log rows (by ids) for uploaded / replaced / removed images. Needs nowo-tech/audit-kit-bundle with nowo_audit_kit.action_log enabled. "audit: true" enables it.')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('user_id_field')
                            ->info('Action log of user relations (AbstractActionLog) only: the user field that holds the owner image subject id when it is not the identifier (e.g. uuid). Null: subject ids are the identifiers.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && !self::isField($v))
                                ->thenInvalid('owner_images.audit.user_id_field must be a field name.')
                            ->end()
                        ->end()
                        ->append($this->actionsNode('actions', 'Actions recorded for every kind ({kind} is replaced by the kind name; at most 48 characters).'))
                        ->arrayNode('kinds')
                            ->info('Actions of one kind (by kind name), over "actions".')
                            ->useAttributeAsKey('name')
                            ->normalizeKeys(false)
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('uploaded')->defaultNull()->end()
                                    ->scalarNode('replaced')->defaultNull()->end()
                                    ->scalarNode('removed')->defaultNull()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('storage')
                    ->info('Service id of your OwnerImageStorageInterface (null: StorageKitBundle 1.6+).')
                    ->defaultNull()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('entity_manager')
                            ->info('Entity manager that maps the user_kit_owner_image table (null: the default one).')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('twig')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('sizes')
                            ->info('Named display sizes of owner_image() / user_avatar() (name => CSS pixels; "md" is the fallback).')
                            ->useAttributeAsKey('name')
                            ->normalizeKeys(false)
                            ->integerPrototype()->min(8)->max(1024)->end()
                            ->defaultValue(OwnerImageExtension::SIZES)
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => self::notAUserKind($v['kinds'], $v['avatar_kind']))
                ->thenInvalid('nowo_user_kit.owner_images.avatar_kind names a kind whose subject is not "user".')
            ->end();

        return $node;
    }

    /**
     * The `uploaded` / `replaced` / `removed` actions.
     */
    private function actionsNode(string $name, string $info): ArrayNodeDefinition
    {
        $node     = (new TreeBuilder($name))->getRootNode();
        $children = $node->info($info)->addDefaultsIfNotSet()->children();
        foreach (self::DEFAULT_AUDIT_ACTIONS as $change => $default) {
            $children->scalarNode($change)->defaultValue($default)->end();
        }

        return $node;
    }

    /**
     * Whether `$value` is a Doctrine field name.
     */
    public static function isField(mixed $value): bool
    {
        return is_string($value) && preg_match(self::FIELD_PATTERN, $value) === 1;
    }

    /**
     * Whether `$name` is a configured kind of another subject than `user` (user_avatar() would show a logo).
     *
     * @param array<string, array{subject: string}> $kinds
     */
    private static function notAUserKind(array $kinds, mixed $name): bool
    {
        return is_string($name) && isset($kinds[$name]) && $kinds[$name]['subject'] !== OwnerImageKind::SUBJECT_USER;
    }
}
