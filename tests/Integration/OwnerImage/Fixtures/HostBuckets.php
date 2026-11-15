<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures;

use Nowo\StorageKitBundle\Profile\ConfigProfileProviderInterface;
use Nowo\StorageKitBundle\Profile\ConfigProfiles;
use Nowo\StorageKitBundle\Storage\S3Settings;

/**
 * The application bucket of the tests (`primary`, streamed by the application). Placeholder values only.
 */
final class HostBuckets implements ConfigProfileProviderInterface
{
    public bool $available = true;

    public function names(): array
    {
        return ['primary'];
    }

    public function has(string $name): bool
    {
        return $name === 'primary';
    }

    public function delivery(string $name): string
    {
        return ConfigProfiles::DELIVERY_PROXY;
    }

    public function settings(string $name): ?S3Settings
    {
        if (!$this->available || $name !== 'primary') {
            return null;
        }

        return new S3Settings(bucket: 'avatars', accessKey: 'REDACTED-TEST', secret: 'REDACTED-TEST', endpoint: 'http://minio:9000', pathStyle: true);
    }
}
