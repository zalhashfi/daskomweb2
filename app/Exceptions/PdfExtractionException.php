<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A modul document could not be turned into text: the bytes are not a PDF,
 * the structure is damaged, or no readable page was found.
 *
 * The message is safe to store in `modul_documents.reject_reason` (it
 * describes the file, never its content).
 */
class PdfExtractionException extends RuntimeException {}
