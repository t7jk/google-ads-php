<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * A minimal SMTP client (no Composer/PHPMailer — FTP-only hosting). Sends one e-mail, text + HTML.
 *
 * Without an SMTP host (cfg 'host' = '') it falls back to PHP's built-in mail(): see sendViaPhpMail(). Not recommended (unreliable
 * delivery, often marked as spam); it exists so that a fresh installation can send something before SMTP is configured.
 *
 * Supported: 'ssl' encryption (SMTPS, usually port 465), 'tls' (STARTTLS, usually 587) or 'none' (local use only),
 * AUTH PLAIN or LOGIN login (whichever the server announces in EHLO). The server certificate is verified.
 *
 * Pitfalls:
 * - Headers: CR/LF is rejected in addresses, names and subject (header injection); subjects/names are encoded per RFC 2047.
 * - The body is base64-encoded (chunk_split): no problems with line length or a leading dot on a line.
 * - The SMTP password never ends up in error messages or logs (MailError contains only the server's reply).
 */
final class Mailer
{
    private const TIMEOUT = 15; // s: connection and each server reply

    /** @var resource|null */
    private $socket = null;

    /**
     * @param array{host:string,port:int,encryption:string,user:string,password:string,from_email:string,from_name:string,ehlo_host?:string} $cfg
     *        ehlo_host: name announced in EHLO (default: the host of public_base_url; the installer passes it because config.php does not exist yet)
     */
    public function __construct(private readonly array $cfg)
    {
    }

    /** True when mail goes through PHP mail() instead of an SMTP server. */
    public function usesPhpMail(): bool
    {
        return $this->cfg['host'] === '';
    }

    /** Sends the message; on failure throws MailError with a readable English description. */
    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        $from = $this->cfg['from_email'];
        foreach ([$to, $from] as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                throw new MailError("Invalid e-mail address: $addr");
            }
        }
        $parts = $this->compose($to, $subject, $text, $html);
        if ($this->usesPhpMail()) {
            $this->sendViaPhpMail($parts);
            return;
        }
        $message = "To: $to\r\nSubject: {$parts['subject']}\r\n" . implode("\r\n", $parts['headers']) . "\r\n\r\n" . $parts['body'];
        try {
            $this->connect();
            $this->command("MAIL FROM:<$from>", [250]);
            $this->command("RCPT TO:<$to>", [250, 251]);
            $this->command('DATA', [354]);
            $this->command($message . "\r\n.", [250]);
            $this->command('QUIT', [221]);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    /**
     * PHP mail(): the "To" and "Subject" headers are added by mail() itself from its arguments (so they are not in $parts['headers']);
     * the envelope sender (-f) is set only for a plain address, because mail() passes it to the sendmail command line.
     * mail() returning true only means "handed to the local MTA", never "delivered": that is why this path is not recommended.
     * @param array{subject:string,headers:list<string>,body:string,to:string} $parts
     */
    private function sendViaPhpMail(array $parts): void
    {
        $from = $this->cfg['from_email'];
        $params = preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$/', $from) === 1 ? '-f' . $from : '';
        $sent = @mail($parts['to'], $parts['subject'], $parts['body'], implode("\r\n", $parts['headers']), $params);
        if (!$sent) {
            throw new MailError('PHP mail() refused the message (mail is probably not set up on this server). Configure SMTP in Settings.');
        }
    }

    private function connect(): void
    {
        $host = $this->cfg['host'];
        $enc = $this->cfg['encryption'];
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $this->cfg['port'];
        $socket = @stream_socket_client($remote, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if ($socket === false) {
            throw new MailError("Could not connect to the SMTP server $host:{$this->cfg['port']} ($errstr). Check the host, port and encryption.");
        }
        stream_set_timeout($socket, self::TIMEOUT);
        $this->socket = $socket;

        $this->expect([220]);
        $ehlo = $this->ehlo();
        if ($enc === 'tls') {
            $this->command('STARTTLS', [220]);
            $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if ($ok !== true) {
                throw new MailError('Could not enable STARTTLS encryption (server certificate?). Try SSL mode on port 465.');
            }
            $ehlo = $this->ehlo(); // after STARTTLS the server announces its capabilities again (including AUTH)
        }
        if ($this->cfg['user'] !== '') {
            $this->authenticate($ehlo);
        }
    }

    /** Returns the EHLO reply (the server extension list). */
    private function ehlo(): string
    {
        $name = $this->cfg['ehlo_host'] ?? (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost');
        return $this->command('EHLO ' . $name, [250]);
    }

    private function authenticate(string $ehlo): void
    {
        $user = $this->cfg['user'];
        $pass = $this->cfg['password'];
        try {
            if (preg_match('/^250[ -]AUTH\b.*\bPLAIN\b/mi', $ehlo)) {
                $this->command('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235], true);
                return;
            }
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($user), [334], true);
            $this->command(base64_encode($pass), [235], true);
        } catch (MailError $e) {
            throw new MailError('The SMTP server rejected the username or password. ' . $e->getMessage());
        }
    }

    /** Sends a line and checks the reply code. $secret = do not show the sent line in the error (login credentials). */
    private function command(string $line, array $okCodes, bool $secret = false): string
    {
        if (@fwrite($this->socket, $line . "\r\n") === false) {
            throw new MailError('The connection to the SMTP server was interrupted.');
        }
        return $this->expect($okCodes, $secret ? '(login credentials)' : strtok($line, "\r\n"));
    }

    /** Reads the reply (also multi-line "250-...") and checks the code. */
    private function expect(array $okCodes, string $after = 'connect'): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ($response === '') {
            throw new MailError('The SMTP server did not respond within ' . self::TIMEOUT . ' s.');
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            $cmd = mb_strimwidth($after, 0, 40, '…');
            throw new MailError("The SMTP server rejected the command after \"{$cmd}\": " . trim($response));
        }
        return $response;
    }

    /**
     * Builds the message once for both transports. Headers exclude To and Subject (SMTP adds them in front, mail() adds its own).
     * Header values are checked for CR/LF (header injection); the body is base64, so any text is safe.
     * @return array{to:string,subject:string,headers:list<string>,body:string} subject is already RFC 2047 encoded
     */
    private function compose(string $to, string $subject, string $text, ?string $html): array
    {
        $fromName = $this->cfg['from_name'];
        foreach ([$to, $subject, $fromName, $this->cfg['from_email']] as $value) {
            if (preg_match('/[\r\n]/', $value)) {
                throw new MailError('Newline character not allowed in a message header.');
            }
        }
        $fromEmail = $this->cfg['from_email'];
        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($fromName !== '' ? self::encodeHeader($fromName) . " <$fromEmail>" : $fromEmail),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@$domain>",
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
        ];
        $textHeaders = ['Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
        $encodedText = chunk_split(base64_encode($text));
        $subject = self::encodeHeader($subject);
        if ($html === null) {
            return ['to' => $to, 'subject' => $subject, 'headers' => [...$headers, ...$textHeaders], 'body' => $encodedText];
        }
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $body = "--$boundary\r\n" . implode("\r\n", $textHeaders) . "\r\n\r\n" . $encodedText
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
            . "--$boundary--";
        return ['to' => $to, 'subject' => $subject, 'headers' => [...$headers, "Content-Type: multipart/alternative; boundary=\"$boundary\""], 'body' => $body];
    }

    /** RFC 2047: non-ASCII characters (e.g. Polish letters) in the subject and the sender name. */
    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }
}
