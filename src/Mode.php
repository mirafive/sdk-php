<?php

declare(strict_types=1);

namespace MiraFive;

enum Mode: string
{
    /** Identifier-free: no userId, anonymousId or sessionId, no consent banner needed. */
    case Consentless = 'consentless';

    /** For people who consented, or for consent you already hold. */
    case Full = 'full';
}
