<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * What an uploaded file is to the messenger: a document, a photo, audio, video, a voice message, an animation (GIF).
 */
enum MediaKind: string
{
    case Document = 'document';
    case Photo = 'photo';
    case Audio = 'audio';
    case Video = 'video';
    case Voice = 'voice';
    case Animation = 'animation';

    /** The Bot API method that sends it; the form field is the value. */
    public function method(): string
    {
        return 'send' . \ucfirst($this->value);
    }
}
