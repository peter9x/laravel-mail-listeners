<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

/**
 * A set of mail accounts defined in code, keyed by email: the central ones ({@see Facades\MailListeners::mailbox()})
 * or the ones of a tenant ({@see Facades\MailListeners::tenantMailboxes()}).
 */
final class MailboxDefinitions
{
    /**
     * @var array<string, MailboxDefinition>
     */
    private array $mailboxes = [];

    /**
     * Define a mail account, or get its definition when it is already defined.
     */
    public function mailbox(string $email): MailboxDefinition
    {
        $definition = new MailboxDefinition($email);

        return $this->mailboxes[$definition->email] ??= $definition;
    }

    /**
     * @return array<string, MailboxDefinition>
     */
    public function all(): array
    {
        return $this->mailboxes;
    }

    public function has(string $email): bool
    {
        return array_key_exists(mb_strtolower(mb_trim($email)), $this->mailboxes);
    }
}
