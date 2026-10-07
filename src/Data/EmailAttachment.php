<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Data;

/**
 * A file attached to an email, held in memory only (never stored).
 */
final readonly class EmailAttachment
{
    public function __construct(
        public string $name,
        public ?string $contentType,
        public int $size,
        public string $content,
        public bool $isInline = false,
    ) {}

    /**
     * Same rule as {@see isEmbeddedImage()}, for connectors deciding from attachment metadata only.
     */
    public static function isEmbeddedImageType(bool $isInline, ?string $contentType, ?string $name): bool
    {
        if (! $isInline) {
            return false;
        }

        if ($contentType !== null && str_starts_with(mb_strtolower($contentType), 'image/')) {
            return true;
        }

        return in_array(mb_strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp', 'svg', 'tif', 'tiff'], true);
    }

    /**
     * Lowercased file extension, without the dot (e.g. "csv").
     */
    public function extension(): string
    {
        return mb_strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /**
     * Whether this is an image embedded in the body (e.g. a signature logo) rather than a real attached file.
     * Files sent inline by some mail clients (e.g. a PDF from Apple Mail) are still real attachments.
     */
    public function isEmbeddedImage(): bool
    {
        return self::isEmbeddedImageType($this->isInline, $this->contentType, $this->name);
    }
}
