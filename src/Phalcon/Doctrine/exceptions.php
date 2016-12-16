<?php

namespace VideoRecruit\Phalcon\Doctrine;

/**
 * Common exception interface.
 */
interface Exception
{
}

/**
 * Class InvalidArgumentException
 */
class InvalidArgumentException extends \InvalidArgumentException implements Exception
{
}
