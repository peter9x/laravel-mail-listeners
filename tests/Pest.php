<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Microsoft\Graph\Generated\Models\ODataErrors\MainError;
use Microsoft\Graph\Generated\Models\ODataErrors\ODataError;
use Microsoft\Kiota\Abstractions\ApiException;
use Mupy\MailListeners\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function graphODataError(string $code, string $message, int $status): ODataError
{
    $mainError = new MainError();
    $mainError->setCode($code);
    $mainError->setMessage($message);

    $error = new ODataError();
    $error->setError($mainError);
    $error->setResponseStatusCode($status);

    return $error;
}

function graphApiError(int $status): ApiException
{
    $error = new ApiException();
    $error->setResponseStatusCode($status);

    return $error;
}
