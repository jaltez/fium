<?php

declare(strict_types=1);

namespace Fium\Runtime;

/**
 * Write target for a streaming response (see Response::stream). Each send() emits one
 * chunk to the client immediately as it's produced; event() formats an SSE event.
 */
final class Stream
{
    /**
     * @param \Closure(string): void $writeFrame writes one length-prefixed frame whose
     *     payload is the given JSON string.
     */
    public function __construct(
        private \Closure $writeFrame,
        private string $requestId,
    ) {
    }

    /**
     * Emit a raw chunk of bytes verbatim. For non-SSE streaming (chunked HTML, NDJSON,
     * etc.) this is all you need.
     */
    public function send(string $chunk): void
    {
        ($this->writeFrame)(json_encode([
            'type' => 'stream_chunk',
            'request_id' => $this->requestId,
            'data' => base64_encode($chunk),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Emit an SSE-formatted event: `event: <name>\ndata: <data>\nid: <id>\n\n`.
     * Multi-line data is split across `data:` lines per the SSE spec.
     */
    public function event(string $data, string $name = '', string $id = ''): void
    {
        $payload = '';
        if ($name !== '') {
            $payload .= "event: {$name}\n";
        }
        $payload .= 'data: ' . str_replace("\n", "\ndata: ", $data) . "\n";
        if ($id !== '') {
            $payload .= "id: {$id}\n";
        }
        $payload .= "\n";

        $this->send($payload);
    }
}
