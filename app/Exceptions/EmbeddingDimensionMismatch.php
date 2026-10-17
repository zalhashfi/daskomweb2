<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A returned embedding vector does not match
 * `AiConfig::active()->embedding_dimension`.
 *
 * Thrown inside the persist transaction so the rollback also discards any
 * chunk written for the new index version. The message starts with
 * `IngestModulDocument::ERROR_EMBEDDING_DIMENSION_MISMATCH` and is stored as
 * the document's `reject_reason`.
 */
class EmbeddingDimensionMismatch extends RuntimeException {}
