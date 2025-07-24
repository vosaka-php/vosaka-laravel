<?php

declare(strict_types=1);

namespace vosaka\laravel\commands;

use Exception;
use Generator;
use Throwable;
use Illuminate\Console\Command;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
// use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use venndev\vosaka\VOsaka;
use venndev\vosaka\net\tcp\TCP;
use venndev\vosaka\net\tcp\TCPConnection;
use venndev\vosaka\net\tcp\TCPServer;

class VOsakaServer extends Command
{
    protected $signature = 'vosaka:serve 
                            {--host=127.0.0.1 : The host address to serve on}
                            {--port=8080 : The port to serve on}
                            {--workers=1 : Number of worker processes}';

    protected $description = 'Run an asynchronous VOsaka HTTP server for Laravel';

    private TCPServer $listener;
    private bool $running = true;
    private array $activeConnections = [];

    public function handle(): int
    {
        $host = $this->option('host');
        $port = $this->option('port');

        $this->info("Starting VOsaka Laravel server on http://{$host}:{$port}");

        // Register signal handlers for graceful shutdown
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        pcntl_signal(SIGTERM, [$this, 'handleShutdown']);

        // Start the async server
        VOsaka::spawn($this->startServer($host, $port ?? 8080));
        VOsaka::run();

        return 0;
    }

    /**
     * Start the async HTTP server
     */
    private function startServer(string $host, int $port): Generator
    {
        try {
            // Bind to the specified host and port
            $this->listener = yield from TCP::listen("{$host}:{$port}")->unwrap();
            $this->info("Server listening on {$host}:{$port}");

            // Accept connections in a loop
            while ($this->running && !$this->listener->isClosed()) {
                $client = yield from $this->listener->accept()->unwrap();

                if ($client === null || $client->isClosed()) {
                    continue;
                }

                // Track active connection
                $connectionId = spl_object_id($client);
                $this->activeConnections[$connectionId] = $client;

                // Handle each client connection asynchronously
                VOsaka::spawn($this->handleConnection($client, $connectionId));
                yield;
            }
        } catch (Exception $e) {
            $this->error("Server error: " . $e->getMessage());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Handle individual client connections
     */
    private function handleConnection(TCPConnection $client, int $connectionId): Generator
    {
        try {
            // Read the HTTP request (up to 16KB)
            $requestData = yield from $client->read(16384)->unwrap();

            if ($requestData === null || $requestData === '') {
                return;
            }

            // Parse HTTP request
            $request = $this->parseHttpRequest($requestData);

            if ($request === null) {
                yield from $this->sendBadRequest($client);
                return;
            }

            // Process the request through Laravel
            $response = yield from $this->processLaravelRequest($request);

            // Send the response
            yield from $client->writeAll($response)->unwrap();
        } catch (Throwable $e) {
            $this->error("Connection error: " . $e->getMessage());
            yield from $this->sendInternalError($client);
        } finally {
            // Clean up connection
            unset($this->activeConnections[$connectionId]);

            if (!$client->isClosed()) {
                $client->close();
            }
        }
    }

    /**
     * Parse raw HTTP request data
     */
    private function parseHttpRequest(string $rawRequest): ?array
    {
        $lines = explode("\r\n", $rawRequest);

        if (empty($lines)) {
            return null;
        }

        // Parse request line
        $requestLine = array_shift($lines);
        $parts = explode(' ', $requestLine);

        if (count($parts) < 3) {
            return null;
        }

        [$method, $uri, $protocol] = $parts;

        // Parse headers
        $headers = [];
        $body = '';
        $headerSection = true;

        foreach ($lines as $line) {
            if ($headerSection && $line === '') {
                $headerSection = false;
                continue;
            }

            if ($headerSection) {
                $headerParts = explode(':', $line, 2);
                if (count($headerParts) === 2) {
                    $headers[trim($headerParts[0])] = trim($headerParts[1]);
                }
            } else {
                $body .= $line . "\r\n";
            }
        }

        // Parse query string
        $uriParts = parse_url($uri);
        $path = $uriParts['path'] ?? '/';
        $query = $uriParts['query'] ?? '';

        return [
            'method' => $method,
            'uri' => $uri,
            'path' => $path,
            'query' => $query,
            'protocol' => $protocol,
            'headers' => $headers,
            'body' => rtrim($body, "\r\n"),
        ];
    }

    /**
     * Process request through Laravel's HTTP kernel
     */
    private function processLaravelRequest(array $parsedRequest): Generator
    {
        try {
            $kernel = app()->make(\Illuminate\Contracts\Http\Kernel::class);

            // Create PSR-7 factories
            $psr17Factory = new Psr17Factory();
            $httpFoundation = new HttpFoundationFactory();
            // $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);

            // Build PSR-7 request
            $serverParams = $this->buildServerParams($parsedRequest);
            $psrRequest = $psr17Factory->createServerRequest(
                $parsedRequest['method'],
                $parsedRequest['uri'],
                $serverParams
            );

            // Add headers
            foreach ($parsedRequest['headers'] as $name => $value) {
                $psrRequest = $psrRequest->withHeader($name, $value);
            }

            // Add body if present
            if (!empty($parsedRequest['body'])) {
                $stream = $psr17Factory->createStream($parsedRequest['body']);
                $psrRequest = $psrRequest->withBody($stream);
            }

            // Convert to Symfony request
            $symfonyRequest = $httpFoundation->createRequest($psrRequest);

            // Convert to Laravel request
            $laravelRequest = \Illuminate\Http\Request::createFromBase($symfonyRequest);

            // Handle the request
            $laravelResponse = $kernel->handle($laravelRequest);

            // Convert response to string
            $responseStr = $this->formatHttpResponse($laravelResponse);

            // Terminate the kernel
            $kernel->terminate($laravelRequest, $laravelResponse);

            return $responseStr;
        } catch (Throwable $e) {
            $this->error("Request processing error: " . $e->getMessage());
            return $this->createErrorResponse(500, 'Internal Server Error');
        }

        // Yield to make this a generator
        yield;
    }

    /**
     * Build server parameters for PSR-7 request
     */
    private function buildServerParams(array $parsedRequest): array
    {
        $host = $this->option('host');
        $port = $this->option('port');

        return [
            'REQUEST_METHOD' => $parsedRequest['method'],
            'REQUEST_URI' => $parsedRequest['uri'],
            'SERVER_PROTOCOL' => $parsedRequest['protocol'],
            'HTTP_HOST' => $parsedRequest['headers']['Host'] ?? "{$host}:{$port}",
            'SERVER_NAME' => $host,
            'SERVER_PORT' => $port,
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '',
            'PATH_INFO' => $parsedRequest['path'],
            'QUERY_STRING' => $parsedRequest['query'],
        ];
    }

    /**
     * Format Laravel response as HTTP response string
     */
    private function formatHttpResponse($laravelResponse): string
    {
        $status = $laravelResponse->getStatusCode();
        $statusText = $laravelResponse->getReasonPhrase() ?: 'OK';

        $response = "HTTP/1.1 {$status} {$statusText}\r\n";

        // Add headers
        foreach ($laravelResponse->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $response .= "{$name}: {$value}\r\n";
            }
        }

        // Add content length if not present
        if (!$laravelResponse->headers->has('Content-Length')) {
            $content = $laravelResponse->getContent();
            $response .= "Content-Length: " . strlen($content) . "\r\n";
        }

        // Add connection close header
        if (!$laravelResponse->headers->has('Connection')) {
            $response .= "Connection: close\r\n";
        }

        $response .= "\r\n";
        $response .= $laravelResponse->getContent();

        return $response;
    }

    /**
     * Send a 400 Bad Request response
     */
    private function sendBadRequest(TCPConnection $client): Generator
    {
        $response = $this->createErrorResponse(400, 'Bad Request');
        yield from $client->writeAll($response)->unwrap();
    }

    /**
     * Send a 500 Internal Server Error response
     */
    private function sendInternalError(TCPConnection $client): Generator
    {
        $response = $this->createErrorResponse(500, 'Internal Server Error');
        yield from $client->writeAll($response)->unwrap();
    }

    /**
     * Create an error response
     */
    private function createErrorResponse(int $code, string $message): string
    {
        $body = json_encode(['error' => $message]);
        $length = strlen($body);

        return "HTTP/1.1 {$code} {$message}\r\n" .
            "Content-Type: application/json\r\n" .
            "Content-Length: {$length}\r\n" .
            "Connection: close\r\n" .
            "\r\n" .
            $body;
    }

    /**
     * Handle shutdown signal
     */
    public function handleShutdown(): void
    {
        $this->info("\nShutting down server...");
        $this->running = false;

        // Close the listener to stop accepting new connections
        if (isset($this->listener) && !$this->listener->isClosed()) {
            $this->listener->close();
        }
    }

    /**
     * Clean up resources
     */
    private function cleanup(): void
    {
        // Close all active connections
        foreach ($this->activeConnections as $connection) {
            if (!$connection->isClosed()) {
                $connection->close();
            }
        }

        $this->info("Server stopped.");
    }
}
