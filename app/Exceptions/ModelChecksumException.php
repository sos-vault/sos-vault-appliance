<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a freshly downloaded AI model fails its sha256 verification.
 *
 * Distinct from a generic download failure so callers can report the run as
 * "aborted" (the bytes arrived but were rejected on integrity grounds and the
 * partial file was discarded) rather than "failed" (network / HTTP / IO error).
 */
class ModelChecksumException extends RuntimeException {}
