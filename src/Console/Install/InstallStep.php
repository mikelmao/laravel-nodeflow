<?php

namespace Nodeflow\Console\Install;

/**
 * One host-wiring requirement.
 *
 * Every check() in the command runs before any apply(), which is what stops a
 * step failing halfway through from leaving a half-wired host. So check() must
 * be strictly read-only.
 */
interface InstallStep
{
    /** The name shown in the report table. */
    public function describe(): string;

    /** Read-only. Never writes. */
    public function check(): InstallOutcome;

    /** Only called when check() returned Writable. */
    public function apply(): InstallOutcome;

    /** The exact text the host must add, when this step cannot write it. */
    public function snippet(): ?string;
}
