<?php
declare(strict_types=1);

/**
 * Versand über das lokale Mailsystem von KeyHelp (Postfix) per mail().
 * Wichtig: In KeyHelp für martensfarm.de SPF und DKIM aktivieren, sonst landen die Mails im Spam.
 */
function send_mail(string $to, string $subject, string $text): bool
{
    $from     = (string) config('mail.from_address');
    $fromName = (string) config('mail.from_name', 'MartensFarm');

    // Header-Injection verhindern
    foreach ([$to, $from, $fromName] as $v) {
        if (preg_match('/[\r\n]/', $v)) {
            return false;
        }
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $headers = [
        'From'                      => mb_encode_mimeheader($fromName, 'UTF-8') . " <{$from}>",
        'Reply-To'                  => $from,
        'MIME-Version'              => '1.0',
        'Content-Type'              => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
        'X-Auto-Response-Suppress'  => 'All',
        'Auto-Submitted'            => 'auto-generated',
    ];

    $ok = mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $text, $headers, '-f' . $from);
    if (!$ok) {
        error_log("MartensFarm: Mailversand an {$to} fehlgeschlagen");
    }
    return $ok;
}

function send_login_mail(array $user, string $token, string $code, int $minutes): void
{
    $link = url('login/link?t=' . $token);
    $appName = (string) config('app.name', 'MartensFarm');
    $text = <<<TXT
Moin {$user['nickname']},

hier ist dein Anmelde-Link für {$appName}:

{$link}

Oder gib diesen Code auf der Anmeldeseite ein:

    {$code}

Link und Code gelten {$minutes} Minuten und nur einmal.
Wenn du dich nicht anmelden wolltest, ignoriere diese Mail einfach.

Viele Grüße vom Hof
TXT;
    send_mail($user['email'], "Dein Anmelde-Link für {$appName}", $text);
}
