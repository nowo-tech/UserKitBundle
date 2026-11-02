<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

/**
 * REQ-ORM-002: prepends `sessions.doctrine.table_prefix` to the tables, unique constraints and indexes of the
 * bundle-owned entities (namespaces `Nowo\UserKitBundle\Sessions\Entity\` and `…\Sessions\Epoch\Entity\`). Host and third-party metadata is never
 * touched; an empty prefix is a no-op.
 */
final readonly class TablePrefixListener
{
    /** Namespace of the bundle-owned entities. */
    public const ENTITY_NAMESPACE = 'Nowo\\UserKitBundle\\Sessions\\Entity\\';

    /** Namespace of the remember-me epoch entity (`sessions.remember_me_epoch`). */
    public const EPOCH_ENTITY_NAMESPACE = 'Nowo\\UserKitBundle\\Sessions\\Epoch\\Entity\\';

    public function __construct(
        private string $prefix = '',
    ) {
    }

    /**
     * Rewrites the table and index names of a bundle entity.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $name     = $metadata->getName();
        if ($this->prefix === '' || (!str_starts_with($name, self::ENTITY_NAMESPACE) && !str_starts_with($name, self::EPOCH_ENTITY_NAMESPACE))) {
            return;
        }

        $table         = $metadata->table;
        $table['name'] = $this->prefix . $metadata->getTableName();
        foreach (['uniqueConstraints', 'indexes'] as $kind) {
            if (!isset($table[$kind])) {
                continue;
            }
            $renamed = [];
            foreach ($table[$kind] as $name => $definition) {
                $renamed[$this->prefix . $name] = $definition;
            }
            $table[$kind] = $renamed;
        }
        $metadata->setPrimaryTable($table);
    }
}
