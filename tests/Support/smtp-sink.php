<?php

/**
 * A local SMTP catcher for tests. It is never run by hand.
 *
 *   php smtp-sink.php <dir> <maxMessages>
 *
 * Listens on 127.0.0.1 only, on a free port it writes to <dir>/port, stores each message as
 * <dir>/NNN.eml and forwards nothing anywhere. It exits after <maxMessages> messages, or after
 * 60 seconds, whichever comes first.
 */
[$self, $dir, $max] = $argv;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (! $server) {
    fwrite(STDERR, "cannot listen: {$error}\n");
    exit(1);
}

// Written aside and renamed into place, so the port file never exists without its content.
// (A reader once saw the file empty, under load, and read port 0.)
file_put_contents($dir.'/port.tmp', (string) parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT));
rename($dir.'/port.tmp', $dir.'/port');

$deadline = microtime(true) + 60;
$stored = 0;

while ($stored < (int) $max && microtime(true) < $deadline) {
    $client = @stream_socket_accept($server, 1);
    if (! $client) {
        continue;
    }

    stream_set_timeout($client, 10);
    $say = fn (string $text) => fwrite($client, $text."\r\n");
    $say('220 sink ready');
    $data = null;

    while (($line = fgets($client)) !== false) {
        if ($data !== null) {
            if (rtrim($line, "\r\n") === '.') {
                file_put_contents(sprintf('%s/%03d.eml', $dir, ++$stored), $data);
                $data = null;
                $say('250 stored');
            } else {
                // SMTP doubles a leading dot, so take the extra one back off.
                $data .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            }

            continue;
        }

        $command = strtoupper(substr(trim($line), 0, 4));

        if ($command === 'EHLO') {
            fwrite($client, "250-sink\r\n250 OK\r\n");
        } elseif ($command === 'DATA') {
            $data = '';
            $say('354 go');
        } elseif ($command === 'QUIT') {
            $say('221 bye');
            break;
        } else {
            $say('250 OK');
        }
    }

    fclose($client);
}
