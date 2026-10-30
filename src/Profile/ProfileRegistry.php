<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Profile;

use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;

/**
 * Holds the configured profiles and resolves the profile of a user object by class (inheritance supported).
 *
 * Resolutions are memoised per class; {@see reset()} (called by `services_resetter` between requests in
 * FrankenPHP worker mode) drops that memo, so no state outlives a request.
 */
final class ProfileRegistry implements ResetInterface
{
    /** @var array<string, ProfileSettings> */
    private array $byName = [];

    /** @var array<class-string, ProfileSettings> */
    private array $byExactClass;

    /** @var array<class-string, ProfileSettings|null> */
    private array $resolveCache = [];

    /**
     * Builds the profile settings from the processed configuration.
     *
     * @param array<string, array<string, mixed>> $profiles
     */
    public function __construct(array $profiles, private readonly string $defaultProfileName)
    {
        /** @var array<class-string, ProfileSettings> $byExactClass */
        $byExactClass = [];

        foreach ($profiles as $name => $config) {
            $settings = ProfileSettings::fromConfig($name, $config);

            $this->byName[$name]                = $settings;
            $byExactClass[$settings->userClass] = $settings;
        }

        $this->byExactClass = $byExactClass;
    }

    /**
     * Returns the profile with the given name.
     */
    public function getByName(string $name): ProfileSettings
    {
        if (!isset($this->byName[$name])) {
            throw new UnknownProfileException($name);
        }

        return $this->byName[$name];
    }

    /**
     * Returns the configured default profile.
     */
    public function getDefault(): ProfileSettings
    {
        return $this->getByName($this->defaultProfileName);
    }

    /**
     * Returns the profile whose user class matches the object (exact class first, then inheritance), or null.
     */
    public function resolveForObject(object $object): ?ProfileSettings
    {
        $class = $object::class;

        if (array_key_exists($class, $this->resolveCache)) {
            return $this->resolveCache[$class];
        }

        if (isset($this->byExactClass[$class])) {
            // @igor-ignore - Per-class memo, cleared by reset() (ResetInterface).
            return $this->resolveCache[$class] = $this->byExactClass[$class];
        }

        foreach ($this->byExactClass as $userClass => $profile) {
            if ($object instanceof $userClass) {
                // @igor-ignore - Per-class memo, cleared by reset() (ResetInterface).
                return $this->resolveCache[$class] = $profile;
            }
        }

        // @igor-ignore - Per-class memo, cleared by reset() (ResetInterface).
        return $this->resolveCache[$class] = null;
    }

    /**
     * Drops the per-class resolution memo (the configured profiles are immutable and kept).
     */
    public function reset(): void
    {
        $this->resolveCache = [];
    }

    /**
     * Whether at least one profile enables the account status check.
     */
    public function hasAccountStatusEnabled(): bool
    {
        foreach ($this->byName as $profile) {
            if ($profile->accountStatusEnabled) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether at least one profile enables last-activity tracking.
     */
    public function hasLastActivityEnabled(): bool
    {
        foreach ($this->byName as $profile) {
            if ($profile->lastActivityEnabled) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether at least one profile invalidates sessions when an account is disabled.
     */
    public function hasSessionInvalidationEnabled(): bool
    {
        foreach ($this->byName as $profile) {
            if ($profile->invalidateSessionsOnDisable) {
                return true;
            }
        }

        return false;
    }
}
