<?php

declare(strict_types=1);

namespace MiraFive\Flags;

enum ErrorCode: string
{
    /** The flag needs a newer SDK, or its entry cannot be read. */
    case Unsupported = 'UNSUPPORTED';

    /** No document yet, or segment membership is still being looked up. */
    case NotReady = 'NOT_READY';

    case FlagNotFound = 'FLAG_NOT_FOUND';

    /** Decided, but a segment condition could not be answered and counted as false. */
    case MembershipUnavailable = 'MEMBERSHIP_UNAVAILABLE';

    /** An experiment counted in the browser: a server read gets the default. */
    case NotAllowed = 'NOT_ALLOWED';
}
