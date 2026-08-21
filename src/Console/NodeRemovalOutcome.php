<?php

namespace Nodeflow\Console;

/**
 * What NodeRegistrationWriter::removeFrom() did.
 *
 * Deliberately NOT extra cases on NodeRegistrationOutcome (E38). `Appended` is
 * meaningless for a removal and `Removed` for an append, and growing that enum
 * would force every match() Plans 1 and 5 shipped to gain arms it can never hit
 * or throw UnhandledMatchError.
 */
enum NodeRemovalOutcome
{
    /** At least one resolved entry was removed and the result still parses. */
    case Removed;

    /**
     * No entry in the target array RESOLVES to the requested class. Not the same
     * as "the class name does not appear": a name that appears but resolves to a
     * different class under the file's own namespace and imports is NotPresent,
     * and so is one that appears only inside a comment.
     */
    case NotPresent;

    /**
     * A resolved entry was found, but its syntax is one this writer will not edit
     * — so the caller must not read NotPresent as "nothing to do". Refusing here
     * is what stops extraction proceeding to leave a host whose provider names a
     * class that no longer exists.
     */
    case EntryUnsupported;

    case ProviderMissing;
    case AnchorMissing;
    case AnchorAmbiguous;

    /** The entry shares its line with a sibling entry (E39). File untouched. */
    case EntryAmbiguous;

    /**
     * A write was attempted, but the re-read found the result either failed to
     * parse or still carried a resolved reference. Original bytes restored.
     */
    case WriteFailed;
}
