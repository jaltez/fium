<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Fium\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativePath = str_replace('\\', '/', substr($class, strlen($prefix)));
    $filePath = __DIR__ . '/src/' . $relativePath . '.php';

    if (is_file($filePath)) {
        require $filePath;
    }
});

use Fium\Application;

$routesPath = $argv[1] ?? __DIR__ . '/routes.php';
$application = Application::boot($routesPath);

$stdin = fopen('php://stdin', 'r');
$stdout = fopen('php://stdout', 'w');

if ($stdin === false || $stdout === false) {
    fwrite(STDERR, "Unable to open worker streams\n");
    exit(1);
}

// Optional IPC profiling (FIUM_PROFILE). When enabled, each per-request stage is timed
// with hrtime and cumulative means are written to stderr every 200 requests as
// "PROFILE_PHP ...". Wire bytes are unchanged — this only splits raw I/O from
// (de)serialization so each stage can be timed.
$PROFILE = in_array(strtolower((string) getenv('FIUM_PROFILE')), ['1', 'true', 'yes', 'on'], true);
$pAcc = ['n' => 0, 'read' => 0, 'decode' => 0, 'dispatch' => 0, 'encode' => 0, 'write' => 0];

/**
 * Write a length-prefixed raw payload (bytes) to a stream.
 * Format: 4-byte big-endian u32 length, followed by the payload bytes.
 */
function write_frame_raw($handle, string $payload): void
{
    $len = strlen($payload);
    $data = pack('N', $len) . $payload;
    $remaining = strlen($data);
    $offset = 0;
    while ($remaining > 0) {
        $written = fwrite($handle, substr($data, $offset, $remaining));
        if ($written === false) {
            break;
        }
        $offset += $written;
        $remaining -= $written;
    }
}

/**
 * Read a length-prefixed raw payload (bytes) from a stream.
 * Returns the raw body string, or null on EOF / oversize frame.
 */
function read_frame_raw($handle): ?string
{
    $header = '';
    while (strlen($header) < 4) {
        $chunk = fread($handle, 4 - strlen($header));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $header .= $chunk;
    }

    $arr = unpack('N', $header);
    $len = $arr[1];

    if ($len > 16 * 1024 * 1024) {
        return null;
    }

    $body = '';
    while (strlen($body) < $len) {
        $chunk = fread($handle, $len - strlen($body));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $body .= $chunk;
    }

    return $body;
}

function emit_profile_summary_if_due(): void
{
    global $PROFILE, $pAcc;
    if (!$PROFILE || $pAcc['n'] === 0 || $pAcc['n'] % 200 !== 0) {
        return;
    }
    $n = $pAcc['n'];
    $mean = static function (int $total) use ($n): int {
        return (int) ($total / $n);
    };
    fwrite(STDERR, sprintf(
        "PROFILE_PHP n=%d read=%d decode=%d dispatch=%d encode=%d write=%d\n",
        $n,
        $mean($pAcc['read']),
        $mean($pAcc['decode']),
        $mean($pAcc['dispatch']),
        $mean($pAcc['encode']),
        $mean($pAcc['write'])
    ));
}

// Boot protocol: send route manifest as the first frame.
write_frame_raw($stdout, $application->bootManifest());
fflush($stdout);

while (true) {
    $t0 = hrtime(true);
    $raw = read_frame_raw($stdin);
    $t1 = hrtime(true);
    if ($raw === null) {
        break;
    }

    $request = json_decode($raw, true);
    $t2 = hrtime(true);

    if (!is_array($request)) {
        write_frame_raw($stdout, json_encode([
            'protocol_version' => 1,
            'request_id' => null,
            'status' => 500,
            'headers' => ['content-type' => ['application/json']],
            'cookies' => [],
            'body' => '{"ok":false,"error":"invalid_request"}',
            'error' => ['kind' => 'invalid_request', 'message' => 'Malformed request frame'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($stdout);
        continue;
    }

    // Validate protocol version before dispatching.
    $protocolVersion = isset($request['protocol_version']) ? (int) $request['protocol_version'] : 0;
    if ($protocolVersion !== 1) {
        write_frame_raw($stdout, json_encode([
            'protocol_version' => 1,
            'request_id' => $request['request_id'] ?? null,
            'status' => 500,
            'headers' => ['content-type' => ['application/json']],
            'cookies' => [],
            'body' => '{"ok":false,"error":"protocol_version_mismatch"}',
            'error' => ['kind' => 'protocol_version_mismatch', 'message' => "Protocol version {$protocolVersion} is not supported"],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($stdout);
        continue;
    }

    $response = $application->handleWorkerRequest($request);
    $t3 = hrtime(true);

    $payload = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $t4 = hrtime(true);

    write_frame_raw($stdout, $payload);
    fflush($stdout);
    $t5 = hrtime(true);

    if ($PROFILE) {
        $pAcc['n']++;
        $pAcc['read'] += (int) ($t1 - $t0);
        $pAcc['decode'] += (int) ($t2 - $t1);
        $pAcc['dispatch'] += (int) ($t3 - $t2);
        $pAcc['encode'] += (int) ($t4 - $t3);
        $pAcc['write'] += (int) ($t5 - $t4);
        emit_profile_summary_if_due();
    }
}
