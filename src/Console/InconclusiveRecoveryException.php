<?php

namespace Nodeflow\Console;

use InvalidArgumentException;

/** Carries only fixed, user-actionable recovery context through rollback layers. */
final class InconclusiveRecoveryException extends InvalidArgumentException
{
}
