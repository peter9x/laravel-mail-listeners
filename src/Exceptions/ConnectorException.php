<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Exceptions;

use Microsoft\Graph\Generated\Models\ODataErrors\ODataError;
use Microsoft\Kiota\Abstractions\ApiException;
use RuntimeException;
use Throwable;

/**
 * Error reading a mail account, with a human readable message (provider errors often have none).
 * The provider exception is kept as the previous exception.
 */
final class ConnectorException extends RuntimeException
{
    /**
     * Microsoft Graph errors carry their details in the OData error body, not in the exception message.
     */
    public static function fromMicrosoftGraph(ApiException $exception): self
    {
        $status = $exception->getResponseStatusCode();
        $details = $exception->getMessage();

        if ($exception instanceof ODataError && $exception->getError() !== null) {
            $details = mb_trim(implode(': ', array_filter([
                $exception->getError()->getCode(),
                $exception->getError()->getMessage(),
            ])));
        }

        $message = $details !== '' ? $details : __('Microsoft Graph API error');

        if ($status !== null) {
            $message .= " (HTTP {$status})";
        }

        return new self($message, (int) $status, $exception);
    }

    /**
     * IMAP (Webklex) errors are generic ("connection failed"): the cause is in the chained exceptions.
     */
    public static function fromImap(Throwable $exception): self
    {
        $messages = [];

        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $message = mb_trim($current->getMessage());

            if ($message !== '' && ! in_array($message, $messages, true)) {
                $messages[] = $message;
            }
        }

        $details = $messages !== [] ? implode(' — ', $messages) : __('IMAP error');

        return new self(class_basename($exception).': '.$details, 0, $exception);
    }
}
