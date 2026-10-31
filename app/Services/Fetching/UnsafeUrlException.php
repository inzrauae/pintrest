<?php

namespace App\Services\Fetching;

use RuntimeException;

/** The URL is not allowed (bad scheme, private network, blocked host...). Never retried. */
class UnsafeUrlException extends RuntimeException {}
