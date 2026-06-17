<?php

namespace ParseForArtisans\Exceptions;

use Exception;

/**
 * Thrown by ->wait() when the parse does not reach a terminal state in time.
 */
class ParseTimeoutException extends Exception {}
