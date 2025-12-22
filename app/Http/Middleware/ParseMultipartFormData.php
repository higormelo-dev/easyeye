<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\{Request, UploadedFile};
use Symfony\Component\HttpFoundation\Response;

class ParseMultipartFormData
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('PUT') || $request->isMethod('PATCH')) {
            if (str_contains($request->header('Content-Type', ''), 'multipart/form-data')) {
                $this->parseMultipartFormData($request);
            }
        }

        return $next($request);
    }

    private function parseMultipartFormData(Request $request): void
    {
        $contentType = $request->header('Content-Type');
        preg_match('/boundary=(.+)$/', $contentType, $matches);

        if (!isset($matches[1])) {
            return;
        }

        $boundary = trim($matches[1], '-');
        $rawData  = $request->getContent();

        // Split by boundary
        $parts = explode('--' . $boundary, $rawData);

        $data  = [];
        $files = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if (empty($part) || $part === '--') {
                continue;
            }

            // Split headers and body
            $headerEndPos = strpos($part, "\r\n\r\n");

            if ($headerEndPos === false) {
                continue;
            }

            $rawHeaders = substr($part, 0, $headerEndPos);
            $body       = substr($part, $headerEndPos + 4);

            // Parse headers
            $headers = [];

            foreach (explode("\r\n", $rawHeaders) as $header) {
                if (str_contains($header, ':')) {
                    [$name, $value]                   = explode(':', $header, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }

            // Get content disposition
            $contentDisposition = $headers['content-disposition'] ?? '';

            // Extract name
            if (!preg_match('/name="([^"]+)"/', $contentDisposition, $nameMatch)) {
                continue;
            }

            $name = $nameMatch[1];

            // Check if it's a file
            if (preg_match('/filename="([^"]*)"/', $contentDisposition, $filenameMatch)) {
                $filename = $filenameMatch[1];

                if (!empty($filename) && !empty($body)) {
                    // Create temporary file
                    $tempFile = tempnam(sys_get_temp_dir(), 'upload_');
                    file_put_contents($tempFile, $body);

                    $mimeType = $headers['content-type'] ?? 'application/octet-stream';

                    $uploadedFile = new UploadedFile(
                        $tempFile,
                        $filename,
                        $mimeType,
                        null,
                        true
                    );

                    $files[$name] = $uploadedFile;
                }
            } else {
                // Regular form field - limpar completamente
                $cleanBody = trim($body);

                // Remove quebras de linha e caracteres de controle
                $cleanBody = preg_replace('/[\r\n]+/', '', $cleanBody);

                // Remove dashes do boundary que podem ter sobrado
                $cleanBody = ltrim($cleanBody, "-");
                $cleanBody = rtrim($cleanBody, "-");

                // Trim final
                $cleanBody = trim($cleanBody);

                // Se ficou vazio, definir como null ao invés de string vazia
                $data[$name] = $cleanBody === '' ? null : $cleanBody;
            }
        }

        // Merge data into request
        $request->merge($data);

        // Add files to request
        foreach ($files as $name => $file) {
            $request->files->set($name, $file);
        }
    }
}
