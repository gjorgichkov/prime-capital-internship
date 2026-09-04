<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A movement was refused because recording it would break one of the two
 * account rules.
 *
 * These are thrown from inside the recording transaction, so the rollback
 * leaves the client's cash and holdings exactly as they were.
 */
abstract class AccountRuleViolation extends RuntimeException
{
    /**
     * A stable identifier for the rule that was broken, for consumers that
     * need to branch on the reason rather than read the message.
     */
    abstract public function code(): string;

    /**
     * The request field the violation is reported against, which is the field
     * the caller would have to change for the movement to succeed.
     */
    abstract public function field(): string;
}
