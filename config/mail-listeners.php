<?php

declare(strict_types=1);

use Mupy\MailListeners\Connectors\ImapConnector;
use Mupy\MailListeners\Connectors\MicrosoftGraphConnector;

return [

    /*
    |--------------------------------------------------------------------------
    | Connectors
    |--------------------------------------------------------------------------
    |
    | Ways of reading a mail account, keyed by the value stored on the account.
    | Add your own by implementing Mupy\MailListeners\Contracts\MailConnector.
    |
    */
    'connectors' => [
        'microsoft_graph' => MicrosoftGraphConnector::class,
        'imap' => ImapConnector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Context events
    |--------------------------------------------------------------------------
    |
    | Events an account can fire for every new email, keyed by the value stored
    | on the account (accounts may also store event classes directly). Each class must extend Mupy\MailListeners\Events\EmailReceived;
    | your modules then listen to them with listeners extending
    | Mupy\MailListeners\MailListener.
    |
    | 'supplier' => ['class' => App\Events\SupplierEmailReceived::class, 'label' => 'Suppliers'],
    |
    */
    'events' => [],

    /*
    |--------------------------------------------------------------------------
    | Microsoft Graph (Office 365)
    |--------------------------------------------------------------------------
    |
    | Application credentials (client credentials flow, Mail.Read permission),
    | keyed by the tenant name an account selects.
    |
    */
    'microsoft_graph' => [
        'tenants' => [
            'default' => [
                'tenant' => env('MAIL_LISTENERS_GRAPH_TENANT'),
                'client_id' => env('MAIL_LISTENERS_GRAPH_CLIENT_ID'),
                'client_secret' => env('MAIL_LISTENERS_GRAPH_CLIENT_SECRET'),
            ],
        ],
        'default_tenant' => 'default',
        'default_folder' => 'inbox',
    ],

    /*
    |--------------------------------------------------------------------------
    | IMAP
    |--------------------------------------------------------------------------
    */
    'imap' => [
        'default_folder' => 'INBOX',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | `channel`: log channel of the package. By default its own `mail-listeners`
    | channel (storage/logs/mail-listeners-*.log), registered with `level` and
    | `days` unless your config/logging.php defines it. Set any other channel
    | (e.g. "stack") to log with the rest of your app.
    |
    */
    'logging' => [
        'channel' => env('MAIL_LISTENERS_LOG_CHANNEL', 'mail-listeners'),
        'level' => env('MAIL_LISTENERS_LOG_LEVEL', 'debug'),
        'days' => 14,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | `poll`: queue of the jobs reading the accounts.
    | `listeners`: default queue of the listeners (a listener may set its own $queue).
    |
    */
    'queues' => [
        'poll' => env('MAIL_LISTENERS_POLL_QUEUE', 'mail-listeners'),
        'listeners' => env('MAIL_LISTENERS_LISTENERS_QUEUE', 'mail-listeners-listeners'),
    ],

    /*
    | Minutes re-read before the last received email on every poll, to catch emails delivered late.
    */
    'poll_overlap_minutes' => 5,

    /*
    | Days the read emails (metadata only) are kept, pruned with `php artisan model:prune`.
    */
    'retention_days' => 180,

    /*
    | Seconds a listener run stays locked, so an email is never processed twice concurrently by a listener.
    */
    'run_lock_seconds' => 3600,
];
