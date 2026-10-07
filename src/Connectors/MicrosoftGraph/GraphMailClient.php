<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Connectors\MicrosoftGraph;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Microsoft\Graph\Core\Tasks\PageIterator;
use Microsoft\Graph\Generated\Models\Attachment;
use Microsoft\Graph\Generated\Models\FileAttachment;
use Microsoft\Graph\Generated\Models\Message;
use Microsoft\Graph\Generated\Users\Item\MailFolders\Item\Messages\MessagesRequestBuilderGetRequestConfiguration as MailFolderMessagesRequestBuilderGetRequestConfiguration;
use Microsoft\Graph\Generated\Users\Item\Messages\Item\Attachments\AttachmentsRequestBuilderGetRequestConfiguration;
use Microsoft\Graph\Generated\Users\Item\Messages\Item\MessageItemRequestBuilderGetRequestConfiguration;
use Microsoft\Graph\Generated\Users\Item\Messages\MessagesRequestBuilderGetRequestConfiguration;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Mupy\MailListeners\Exceptions\ConnectorException;

/**
 * Read-only access to Office 365 mailboxes through Microsoft Graph, with the application credentials
 * of a tenant configured in `mail-listeners.microsoft_graph.tenants` (Mail.Read permission).
 */
class GraphMailClient
{
    /**
     * Ask Graph for immutable message ids, which do not change when a message is moved to another folder.
     */
    private const array IMMUTABLE_ID_HEADERS = ['Prefer' => 'IdType="ImmutableId"'];

    private ?GraphServiceClient $client = null;

    public function auth(string $tenant): static
    {
        $credentials = config('mail-listeners.microsoft_graph.tenants.'.$tenant);

        if (! is_array($credentials) || empty($credentials['tenant']) || empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            throw new ConnectorException(__('The Microsoft Graph tenant :tenant is not configured.', ['tenant' => $tenant]));
        }

        $this->client = new GraphServiceClient(new ClientCredentialContext(
            (string) $credentials['tenant'],
            (string) $credentials['client_id'],
            (string) $credentials['client_secret'],
        ));

        return $this;
    }

    /**
     * Iterate over every message of a mailbox (or of one of its folders, e.g. "inbox") received within [$from, $to[,
     * oldest first, following all result pages. Returning false from the callback stops the iteration.
     *
     * @param  callable(Message): (bool|void)  $callback
     * @param  list<string>  $select
     * @param  list<string>  $expand  e.g. ['attachments($select=name,contentType,isInline,size)']
     */
    public function eachMessageReceivedBetween(
        string $mailbox,
        DateTimeInterface $from,
        DateTimeInterface $to,
        callable $callback,
        array $select,
        ?string $mailFolder = null,
        array $expand = [],
    ): void {
        $client = $this->client();
        $user = $client->users()->byUserId($mailbox);

        if ($mailFolder !== null) {
            $requestConfig = new MailFolderMessagesRequestBuilderGetRequestConfiguration();
            $queryParameters = MailFolderMessagesRequestBuilderGetRequestConfiguration::createQueryParameters();
        } else {
            $requestConfig = new MessagesRequestBuilderGetRequestConfiguration();
            $queryParameters = MessagesRequestBuilderGetRequestConfiguration::createQueryParameters();
        }

        $queryParameters->filter = implode(' and ', [
            'receivedDateTime ge '.CarbonImmutable::instance($from)->utc()->format('Y-m-d\TH:i:s\Z'),
            'receivedDateTime lt '.CarbonImmutable::instance($to)->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
        $queryParameters->select = $select;
        $queryParameters->expand = $expand !== [] ? $expand : null;
        $queryParameters->orderby = ['receivedDateTime asc'];

        $requestConfig->queryParameters = $queryParameters;
        $requestConfig->headers = self::IMMUTABLE_ID_HEADERS;

        $response = $mailFolder !== null
            ? $user->mailFolders()->byMailFolderId($mailFolder)->messages()->get($requestConfig)->wait()
            : $user->messages()->get($requestConfig)->wait();

        $pageIterator = new PageIterator($response, $client->getRequestAdapter());
        $pageIterator->setHeaders(self::IMMUTABLE_ID_HEADERS);
        $pageIterator->iterate(fn (Message $message): bool => $callback($message) !== false);
    }

    /**
     * @param  list<string>  $select
     * @param  list<string>  $expand
     */
    public function getMessage(string $mailbox, string $messageId, array $select, array $expand = []): Message
    {
        $requestConfig = new MessageItemRequestBuilderGetRequestConfiguration();
        $queryParameters = MessageItemRequestBuilderGetRequestConfiguration::createQueryParameters();
        $queryParameters->select = $select;
        $queryParameters->expand = $expand !== [] ? $expand : null;

        $requestConfig->queryParameters = $queryParameters;
        $requestConfig->headers = self::IMMUTABLE_ID_HEADERS;

        return $this->client()->users()->byUserId($mailbox)->messages()->byMessageId($messageId)->get($requestConfig)->wait();
    }

    /**
     * File attachments of a message, with their content.
     *
     * @return list<FileAttachment>
     */
    public function getMessageFileAttachments(string $mailbox, string $messageId): array
    {
        $requestConfig = new AttachmentsRequestBuilderGetRequestConfiguration();
        $requestConfig->headers = self::IMMUTABLE_ID_HEADERS;

        $response = $this->client()->users()->byUserId($mailbox)->messages()->byMessageId($messageId)->attachments()->get($requestConfig)->wait();

        return array_values(array_filter(
            $response?->getValue() ?? [],
            fn (Attachment $attachment): bool => $attachment instanceof FileAttachment,
        ));
    }

    private function client(): GraphServiceClient
    {
        if (! $this->client instanceof GraphServiceClient) {
            throw new ConnectorException(__('Call auth() with a tenant before using the Microsoft Graph client.'));
        }

        return $this->client;
    }
}
