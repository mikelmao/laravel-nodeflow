<?php

namespace Nodeflow\Console;

/**
 * What NodeRegistrationWriter did. An enum rather than a boolean so the command
 * can explain each case differently: "already registered" needs no action,
 * while a missing anchor means the author must paste a line themselves.
 */
enum NodeRegistrationOutcome
{
    case Appended;
    case AlreadyPresent;
    case ProviderMissing;
    case AnchorMissing;
    case AnchorAmbiguous;

    public function needsManualRegistration(): bool
    {
        return match ($this) {
            self::Appended, self::AlreadyPresent => false,
            default => true,
        };
    }
}
