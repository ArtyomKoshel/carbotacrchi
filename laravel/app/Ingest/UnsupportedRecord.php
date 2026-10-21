<?php

namespace App\Ingest;

use RuntimeException;

/** A contract-valid record the core cannot store yet; it is rejected, not retried. */
final class UnsupportedRecord extends RuntimeException {}
