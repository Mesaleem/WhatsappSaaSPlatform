<?php

namespace App\Support\Security;

use RuntimeException;

/** An outbound URL (or a redirect hop of it) that the OutboundUrlGuard refuses. The message is safe to show. */
class UnsafeOutboundUrlException extends RuntimeException
{
}
