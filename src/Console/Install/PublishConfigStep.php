<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Reports the optional host-owned config publication.
 *
 * An absent config/nodeflow.php uses the package defaults merged by the service
 * provider. A present file is host-owned customization, not drift, and is never
 * overwritten by install. Explicit publication is `php artisan
 * vendor:publish --tag=nodeflow-config`.
 */
final class PublishConfigStep implements InstallStep
{
    public const PATH = 'config/nodeflow.php';

    public function __construct(Filesystem $files, string $basePath) {}

    public function describe(): string
    {
        return 'Config (optional; package defaults are merged)';
    }

    public function check(): InstallOutcome
    {
        return InstallOutcome::AlreadyPresent;
    }

    public function apply(): InstallOutcome
    {
        return $this->check();
    }

    public function snippet(): ?string
    {
        return null;
    }

}
