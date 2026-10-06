<?php

declare(strict_types=1);

namespace NoBuildCMS;

/**
 * Datastar v1 SSE helpers — emit patch events the browser runtime understands.
 *
 * Responses are buffered and sent with a Content-Length so the browser's fetch
 * reader completes immediately instead of waiting for the TCP connection to
 * close. That keeps @get/@post snappy even on the single-threaded `php -S`
 * dev server (which is slow to close streamed connections).
 *
 * @see https://data-star.dev — events: datastar-patch-elements / -signals
 */
final class Ds
{
    private static bool $started = false;

    /** True when the request came from the Datastar runtime (@get/@post/...). */
    public static function isRequest(): bool
    {
        return ($_SERVER['HTTP_DATASTAR_REQUEST'] ?? '') === 'true';
    }

    /** Signals sent by the runtime (query param on GET, JSON body otherwise). */
    public static function signals(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $raw = $_GET['datastar'] ?? '{}';
        } else {
            $raw = file_get_contents('php://input') ?: '{}';
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        ob_start();
        register_shutdown_function(static function (): void {
            if (ob_get_level() > 0) {
                header('Content-Length: ' . ob_get_length());
                ob_end_flush();
            }
        });
    }

    /**
     * Patch elements into the DOM. Default mode "outer" morphs by element id.
     * @param array{selector?:string,mode?:string} $opts
     */
    public static function patchElements(string $html, array $opts = []): void
    {
        self::start();
        echo "event: datastar-patch-elements\n";
        if (!empty($opts['selector'])) {
            echo 'data: selector ' . $opts['selector'] . "\n";
        }
        if (!empty($opts['mode'])) {
            echo 'data: mode ' . $opts['mode'] . "\n";
        }
        foreach (preg_split('/\r?\n/', rtrim($html, "\n")) as $line) {
            echo 'data: elements ' . $line . "\n";
        }
        echo "\n";
    }

    /** Remove elements matching a CSS selector. */
    public static function removeElements(string $selector): void
    {
        self::start();
        echo "event: datastar-patch-elements\n";
        echo "data: mode remove\n";
        echo 'data: selector ' . $selector . "\n\n";
    }

    /** Patch signals (client reactive state). */
    public static function patchSignals(array $signals): void
    {
        self::start();
        echo "event: datastar-patch-signals\n";
        echo 'data: signals ' . json_encode($signals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
}
