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
                ->scalarNode('avatar_kind')
                    ->info('Kind shown by the Twig function user_avatar() (a "user" kind; a name that is not configured shows the initials only).')
                    ->defaultValue('avatar')
                ->end()
                ->scalarNode('access')
                    ->info('Service id of your OwnerImageAccessInterface (null: DefaultOwnerImageAccess — users change their own image, "other" kinds nobody).')
                    ->defaultNull()
                ->end()
                ->scalarNode('pointer_store')
                    ->info('Service id of your OwnerImagePointerStoreInterface (null: the bundle table user_kit_owner_image).')
                    ->defaultNull()
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
     * Whether `$name` is a configured kind of another subject than `user` (user_avatar() would show a logo).
     *
     * @param array<string, array{subject: string}> $kinds
     */
    private static function notAUserKind(array $kinds, mixed $name): bool
    {
        return is_string($name) && isset($kinds[$name]) && $kinds[$name]['subject'] !== OwnerImageKind::SUBJECT_USER;
    }
}
