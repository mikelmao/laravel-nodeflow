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

    /**
     * The anchor was present and unique, and a write was attempted, but the
     * re-read (E11) found the result either failed to parse or did not carry
     * the entry outside a comment — an anchor that looked valid but sat inside
     * a `//` or `/* *\/` comment line, most commonly. The original bytes are
     * restored before this is returned, so the file is untouched either way.
     */
    case WriteFailed;
}
