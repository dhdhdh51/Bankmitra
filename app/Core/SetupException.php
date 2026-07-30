<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * A deployment/configuration problem rather than a bug: missing APP_KEY,
 * unreachable database, schema not imported yet.
 *
 * These are thrown only from code paths that run before the system can
 * possibly be serving real users, and every message is authored by us and
 * contains NO credentials. That is what makes it safe for ErrorRenderer to
 * show the message verbatim in production instead of hiding it behind an
 * opaque reference ID - the admin installing LRMS is the person who needs to
 * read it, and they are usually not the person reading the log file.
 *
 * The underlying driver detail (which may name the DB user) is kept in
 * getPrevious() and only ever written to storage/logs.
 */
final class SetupException extends RuntimeException
{
    /** Short label shown as the page heading. */
    private string $heading;

    /** Optional ordered list of things to check. */
    private array $steps;

    /**
     * @param list<string> $steps
     */
    public function __construct(
        string $message,
        string $heading = 'Setup is not finished',
        array $steps = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->heading = $heading;
        $this->steps = $steps;
    }

    public function heading(): string
    {
        return $this->heading;
    }

    /** @return list<string> */
    public function steps(): array
    {
        return $this->steps;
    }
}
