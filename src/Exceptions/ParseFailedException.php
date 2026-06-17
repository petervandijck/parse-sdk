<?php

namespace ParseForArtisans\Exceptions;

use Exception;

/**
 * Thrown by ->wait() when the parse finishes in a failed state.
 */
class ParseFailedException extends Exception {}
