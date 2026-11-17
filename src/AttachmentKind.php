<?php

declare(strict_types=1);

namespace Mercurius\Messenger;

/**
 * What came with an incoming message: a voice message, audio, a round video note, a photo, a video, an animation
 * (GIF) or a document.
 */
enum AttachmentKind: string
{
    case Voice = 'voice';
    case Audio = 'audio';
    case VideoNote = 'video_note';
    case Photo = 'photo';
    case Video = 'video';
    case Animation = 'animation';
    case Document = 'document';
}
