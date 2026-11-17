<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * A file that came with an incoming message. Only its description: the bytes are downloaded on demand
 * (`Channel::download`). The name and the MIME type come from the sender — untrusted.
 */
final readonly class Attachment
{
    /**
     * @param string $fileId the channel's id of the file, for `Channel::download`
     * @param string $fileName the sender's name of the file, or `<kind>-<message id>.<ext>` when it has none
     * @param int|null $size bytes, when the channel says
     * @param int|null $duration seconds, for voice, audio and video
     * @param bool $forwarded the message was forwarded: the file is not the sender's own
     */
    public function __construct(
        public AttachmentKind $kind,
        public string $fileId,
        public string $fileName,
        public ?string $mimeType,
        public ?int $size,
        public ?int $duration = null,
        public ?int $width = null,
        public ?int $height = null,
        public bool $forwarded = false,
    ) {
    }
}
