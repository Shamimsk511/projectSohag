<?php

namespace App\Support;

/**
 * Click-to-chat links (https://wa.me): the member's phone with the message already typed. Staff press Send in
 * WhatsApp themselves; nothing is sent by the system.
 */
final class WhatsApp
{
    /** Bangladeshi mobile numbers as stored on parties (01XXXXXXXXX). */
    private const string MOBILE = '/^01[3-9][0-9]{8}$/';

    /**
     * Link that opens a chat with this member and the text filled in, or null when the phone is not a mobile number.
     */
    public static function link(?string $phone, string $text): ?string
    {
        if ($phone === null || preg_match(self::MOBILE, $phone) !== 1) {
            return null;
        }

        return 'https://wa.me/880'.substr($phone, 1).'?text='.rawurlencode($text);
    }
}
