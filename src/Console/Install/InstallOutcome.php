<?php

namespace Nodeflow\Console\Install;

/**
 * What one install step found, or did.
 *
 * check() returns AlreadyPresent, Writable or CannotWire. apply() returns Wired
 * or CannotWire. Writable is check()-only and means "missing, and apply() can fix
 * it" — in a normal run the command replaces it with the apply() result, and under
 * --check it survives to mean "would be written".
 *
 * The exit rule, which is a CI contract: non-zero iff any final outcome is
 * CannotWire — or, under --check, CannotWire or Writable. A report is never an
 * outcome: an undefined authorization gate is the correct state immediately after
 * install and must not make the first run red.
 */
enum InstallOutcome
{
    case AlreadyPresent;
    case Writable;
    case Wired;
    case CannotWire;
}
