<?php

namespace Nodeflow\Console;

enum RecoveryIdentityStatus
{
    case Found;
    case Absent;
    case Inconclusive;
}
