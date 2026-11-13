<?php

namespace App\Exceptions;

use RuntimeException;

/** Recovery cannot safely go ahead. The message is written for the administrator to read. */
class AccountRecoveryRefused extends RuntimeException
{
}
