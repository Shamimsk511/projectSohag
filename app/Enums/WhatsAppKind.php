<?php

namespace App\Enums;

/**
 * What a logged WhatsApp tap was for.
 */
enum WhatsAppKind: string
{
    case Reminder = 'reminder';
    case Receipt = 'receipt';
    case Notice = 'notice';
    case Portal = 'portal';
}
