<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Support;

use Illuminate\Support\Str;

/**
 * The inbox an address reaches, as a key to count mails under. It is never an identity: an account keeps
 * its address as it was typed.
 *
 * The address is trimmed of whitespace of every kind, a no-break space at either end included, and written
 * in lower case, and a `+tag` in its local part is dropped, since most providers deliver such a spelling to
 * the same inbox. Gmail ignores the dots in the local part and treats `googlemail.com` as `gmail.com`, so
 * under those two domains the dots are dropped and the domain is read as `gmail.com`. Under any other
 * domain a dot stays, since a dot there can name another inbox. A plus that opens the local part stays, and
 * so do the dots of a local part made of nothing else, where dropping them would leave the local part
 * empty.
 *
 * A value without an `@`, such as a username, is only trimmed and written in lower case.
 */
final readonly class Mailbox
{
    /** The domains Gmail delivers for, and the one each is counted as. */
    private const array GMAIL = ['gmail.com' => 'gmail.com', 'googlemail.com' => 'gmail.com'];

    public static function of(string $address): string
    {
        $mailbox = Str::lower(Str::trim($address));
        $at = strrpos($mailbox, '@');

        if ($at === false) {
            return $mailbox;
        }

        $local = substr($mailbox, 0, $at);
        $domain = substr($mailbox, $at + 1);
        $plus = strpos($local, '+');

        if ($plus !== false && $plus > 0) {
            $local = substr($local, 0, $plus);
        }

        if (array_key_exists($domain, self::GMAIL)) {
            $domain = self::GMAIL[$domain];
            $undotted = str_replace('.', '', $local);
            $local = $undotted === '' ? $local : $undotted;
        }

        return $local.'@'.$domain;
    }
}
