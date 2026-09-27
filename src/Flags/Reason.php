<?php

declare(strict_types=1);

namespace MiraFive\Flags;

enum Reason: string
{
    case Static = 'STATIC';
    case TargetingMatch = 'TARGETING_MATCH';
    case Split = 'SPLIT';
    case Default = 'DEFAULT';
    case Disabled = 'DISABLED';
    case Error = 'ERROR';
}
