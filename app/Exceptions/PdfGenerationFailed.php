<?php

namespace App\Exceptions;

use RuntimeException;

/** The message names only the document type, never its content. */
class PdfGenerationFailed extends RuntimeException
{
}
