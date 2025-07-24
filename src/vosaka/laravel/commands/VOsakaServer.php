<?php

declare(strict_types=1);

namespace vosaka\laravel\commands;

use Exception;
use Generator;
use Throwable;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use venndev\vosaka\VOsaka;
use venndev\vosaka\net\tcp\TCP;
use venndev\vosaka\net\tcp\TCPConnection;
use venndev\vosaka\net\tcp\TCPServer;

class VOsakaServer extends Command
{
    protected $signature = 'vosaka:serve 
                            {--host=127.0.0.1 : The host address to serve on}
                            {--port=8080 : The port to serve on}
                            {--workers=1 : Number of worker processes}
                            {--max-request-size=10485760 : Maximum request size in bytes (default 10MB)}';

    protected $description = 'Run an asynchronous VOsaka HTTP server for Laravel';

    private TCPServer $listener;
    private bool $running = true;
    private array $activeConnections = [];
    private int $maxRequestSize;
    private array $mimeTypes = [
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
    ];

    public function handle(): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $this->maxRequestSize = (int) $this->option('max-request-size');

        // Bootstrap Laravel application
        $this->bootstrapApplication();

        $this->info("Starting VOsaka Laravel server on http://{$host}:{$port}");
        $this->info("Maximum request size: " . $this->formatBytes($this->maxRequestSize));

        // Start the async server
        VOsaka::spawn($this->startServer($host, $port));
        VOsaka::run();

        return 0;
    }

    /**
     * Bootstrap Laravel application
     */
    private function bootstrapApplication(): void
    {
        // Ensure Laravel is fully bootstrapped
        app()->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

        // Load environment variables
        if (!app()->environment('production')) {
            app()->make(\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class)
                ->bootstrap(app());
        }
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
            // Read the HTTP request
            $requestData = yield from $this->readHttpRequest($client);

            if ($requestData === null || $requestData === '') {
                return;
            }

            // Parse HTTP request
            $request = $this->parseHttpRequest($requestData);

            if ($request === null) {
                yield from $this->sendBadRequest($client);
                return;
            }

            // Check if it's a static file request
            if ($this->isStaticFileRequest($request['path'])) {
                yield from $this->handleStaticFile($client, $request['path']);
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
     * Read HTTP request with support for large payloads
     */
    private function readHttpRequest(TCPConnection $client): Generator
    {
        $buffer = '';
        $headerComplete = false;
        $contentLength = 0;
        $bodyLength = 0;

        while (!$headerComplete || $bodyLength < $contentLength) {
            $chunk = yield from $client->read(8192)->unwrap();

            if ($chunk === null || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            // Check if headers are complete
            if (!$headerComplete && strpos($buffer, "\r\n\r\n") !== false) {
                $headerComplete = true;
                $headerEnd = strpos($buffer, "\r\n\r\n");
                $headers = substr($buffer, 0, $headerEnd);

                // Extract Content-Length
                if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $matches)) {
                    $contentLength = (int) $matches[1];

                    // Check max request size
                    if ($contentLength > $this->maxRequestSize) {
                        yield from $this->sendRequestTooLarge($client);
                        return null;
                    }
                }

                $bodyLength = strlen($buffer) - $headerEnd - 4;
            } elseif ($headerComplete) {
                $bodyLength = strlen($buffer) - strpos($buffer, "\r\n\r\n") - 4;
            }

            // Prevent excessive memory usage
            if (strlen($buffer) > $this->maxRequestSize) {
                yield from $this->sendRequestTooLarge($client);
                return null;
            }
        }

        return $buffer;
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
                    $headerName = trim($headerParts[0]);
                    $headerValue = trim($headerParts[1]);

                    // Handle multiple headers with same name
                    if (isset($headers[$headerName])) {
                        if (!is_array($headers[$headerName])) {
                            $headers[$headerName] = [$headers[$headerName]];
                        }
                        $headers[$headerName][] = $headerValue;
                    } else {
                        $headers[$headerName] = $headerValue;
                    }
                }
            } else {
                $body .= $line . "\r\n";
            }
        }

        // Parse query string and path
        $uriParts = parse_url($uri);
        $path = $uriParts['path'] ?? '/';
        $query = $uriParts['query'] ?? '';

        // Parse query parameters
        $queryParams = [];
        if ($query !== '') {
            parse_str($query, $queryParams);
        }

        // Parse form data if content type is form-urlencoded
        $postParams = [];
        $contentType = $headers['Content-Type'] ?? '';
        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false && $body !== '') {
            parse_str(rtrim($body, "\r\n"), $postParams);
        }

        return [
            'method' => $method,
            'uri' => $uri,
            'path' => $path,
            'query' => $query,
            'queryParams' => $queryParams,
            'postParams' => $postParams,
            'protocol' => $protocol,
            'headers' => $headers,
            'body' => rtrim($body, "\r\n"),
        ];
    }

    /**
     * Check if the request is for a static file
     */
    private function isStaticFileRequest(string $path): bool
    {
        // Check if path has a file extension
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if (empty($extension)) {
            return false;
        }

        // Check if file exists in public directory
        $publicPath = public_path(ltrim($path, '/'));
        return file_exists($publicPath) && is_file($publicPath);
    }

    /**
     * Handle static file serving
     */
    private function handleStaticFile(TCPConnection $client, string $path): Generator
    {
        $publicPath = public_path(ltrim($path, '/'));

        if (!file_exists($publicPath) || !is_file($publicPath)) {
            yield from $this->sendNotFound($client);
            return;
        }

        // Get file info
        $fileSize = filesize($publicPath);
        $lastModified = filemtime($publicPath);
        $extension = pathinfo($publicPath, PATHINFO_EXTENSION);
        $mimeType = $this->mimeTypes[$extension] ?? 'application/octet-stream';

        // Build response headers
        $headers = [
            "HTTP/1.1 200 OK",
            "Content-Type: {$mimeType}",
            "Content-Length: {$fileSize}",
            "Last-Modified: " . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            "Cache-Control: public, max-age=3600",
            "Connection: close",
            "",
            ""
        ];

        yield from $client->writeAll(implode("\r\n", $headers))->unwrap();

        // Send file content in chunks
        $handle = fopen($publicPath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk !== false) {
                    yield from $client->writeAll($chunk)->unwrap();
                }
            }
            fclose($handle);
        }
    }

    /**
     * Process request through Laravel's HTTP kernel
     */
    private function processLaravelRequest(array $parsedRequest): Generator
    {
        try {
            $kernel = app()->make(\Illuminate\Contracts\Http\Kernel::class);

            // Create Laravel request
            $laravelRequest = $this->createLaravelRequest($parsedRequest);

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
     * Create Laravel request from parsed HTTP request
     */
    private function createLaravelRequest(array $parsedRequest): Request
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');

        // Build server array
        $server = [
            'REQUEST_METHOD' => $parsedRequest['method'],
            'REQUEST_URI' => $parsedRequest['uri'],
            'SERVER_PROTOCOL' => $parsedRequest['protocol'],
            'HTTP_HOST' => $parsedRequest['headers']['Host'] ?? "{$host}:{$port}",
            'SERVER_NAME' => $host,
            'SERVER_PORT' => $port,
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => public_path('index.php'),
            'PATH_INFO' => $parsedRequest['path'],
            'QUERY_STRING' => $parsedRequest['query'],
            'DOCUMENT_ROOT' => public_path(),
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];

        // Add HTTP headers to server array
        foreach ($parsedRequest['headers'] as $name => $value) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$serverKey] = is_array($value) ? implode(', ', $value) : $value;
        }

        // Handle special headers
        if (isset($parsedRequest['headers']['Content-Type'])) {
            $server['CONTENT_TYPE'] = $parsedRequest['headers']['Content-Type'];
        }
        if (isset($parsedRequest['headers']['Content-Length'])) {
            $server['CONTENT_LENGTH'] = $parsedRequest['headers']['Content-Length'];
        }

        // Create request
        $request = new Request(
            $parsedRequest['queryParams'],
            $parsedRequest['postParams'],
            [],
            $_COOKIE,
            [],
            $server,
            $parsedRequest['body']
        );

        // Handle JSON content
        $contentType = $parsedRequest['headers']['Content-Type'] ?? '';
        if (strpos($contentType, 'application/json') !== false && $parsedRequest['body'] !== '') {
            $json = json_decode($parsedRequest['body'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->request->replace($json);
            }
        }

        // Handle multipart form data
        if (strpos($contentType, 'multipart/form-data') !== false) {
            // This would need a proper multipart parser implementation
            // For now, leaving as TODO
        }

        return $request;
    }

    /**
     * Format Laravel response as HTTP response string
     */
    private function formatHttpResponse(Response $laravelResponse): string
    {
        $status = $laravelResponse->getStatusCode();
        $statusText = Response::$statusTexts[$status] ?? 'Unknown';
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
            if ($content !== false) {
                $response .= "Content-Length: " . strlen($content) . "\r\n";
            }
        }

        // Add connection close header
        if (!$laravelResponse->headers->has('Connection')) {
            $response .= "Connection: close\r\n";
        }

        // Add server header
        if (!$laravelResponse->headers->has('Server')) {
            $response .= "Server: VOsaka/1.0\r\n";
        }

        // Add date header
        if (!$laravelResponse->headers->has('Date')) {
            $response .= "Date: " . gmdate('D, d M Y H:i:s') . " GMT\r\n";
        }

        $response .= "\r\n";

        $content = $laravelResponse->getContent();
        if ($content !== false) {
            $response .= $content;
        }

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
     * Send a 404 Not Found response
     */
    private function sendNotFound(TCPConnection $client): Generator
    {
        $response = $this->createErrorResponse(404, 'Not Found');
        yield from $client->writeAll($response)->unwrap();
    }

    /**
     * Send a 413 Request Entity Too Large response
     */
    private function sendRequestTooLarge(TCPConnection $client): Generator
    {
        $response = $this->createErrorResponse(413, 'Request Entity Too Large');
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
        $body = json_encode([
            'error' => $message,
            'code' => $code,
            'timestamp' => date('c')
        ]);

        $length = strlen($body);

        return "HTTP/1.1 {$code} {$message}\r\n" .
            "Content-Type: application/json\r\n" .
            "Content-Length: {$length}\r\n" .
            "Connection: close\r\n" .
            "Server: VOsaka/1.0\r\n" .
            "Date: " . gmdate('D, d M Y H:i:s') . " GMT\r\n" .
            "\r\n" .
            $body;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
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

        // Close listener
        if (isset($this->listener) && !$this->listener->isClosed()) {
            $this->listener->close();
        }

        $this->info("Server stopped.");
    }
}
