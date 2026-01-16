<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BucketController extends Controller
{
    /**
     * Upload or put a file (POST/PUT)
     * S3-compliant endpoint
     */
    public function put(Request $request, string $bucket, string $path = '')
    {
        try {
            // Normalize the path (remove leading/trailing slashes)
            $path = trim($path, '/');

            if (empty($path)) {
                return $this->errorResponse('InvalidRequest', 'Path cannot be empty', 400);
            }

            // Full path includes bucket name: bucket-name/path/to/file
            $fullPath = $bucket . '/' . $path;

            // Create directory structure if it doesn't exist
            $directory = dirname($fullPath);
            if ($directory !== '.' && $directory !== '') {
                Storage::disk('public')->makeDirectory($directory);
            }

            // Use streaming for better performance
            $stream = fopen('php://input', 'rb');
            if ($stream === false) {
                return $this->errorResponse('InternalError', 'Failed to open input stream', 500);
            }

            // Write stream to file
            $destination = Storage::disk('public')->path($fullPath);
            $destinationDir = dirname($destination);
            if (!is_dir($destinationDir)) {
                mkdir($destinationDir, 0755, true);
            }

            $destinationStream = fopen($destination, 'wb');
            if ($destinationStream === false) {
                fclose($stream);
                return $this->errorResponse('InternalError', 'Failed to create destination file', 500);
            }

            // Stream copy
            stream_copy_to_stream($stream, $destinationStream);

            fclose($stream);
            fclose($destinationStream);

            // Get file info
            $filePath = Storage::disk('public')->path($fullPath);

            // S3-compliant response
            return response('', 200)
                ->header('ETag', '"' . md5_file($filePath) . '"')
                ->header('Content-Length', '0')
                ->header('x-amz-request-id', Str::uuid()->toString());
        } catch (\Exception $e) {
            return $this->errorResponse('InternalError', $e->getMessage(), 500);
        }
    }

    /**
     * Get a file (GET)
     * Note: This should ideally be handled by .htaccess for direct serving
     * But we provide a fallback here
     */
    public function get(Request $request, string $bucket, string $path = '')
    {
        try {
            $path = trim($path, '/');

            if (empty($path)) {
                // List bucket if no path
                return $this->list($request, $bucket);
            }

            // Full path includes bucket name: bucket-name/path/to/file
            $fullPath = $bucket . '/' . $path;

            if (!Storage::disk('public')->exists($fullPath)) {
                return $this->errorResponse('NoSuchKey', 'The specified key does not exist.', 404, $path);
            }

            $filePath = Storage::disk('public')->path($fullPath);
            $mimeType = Storage::disk('public')->mimeType($fullPath) ?: 'application/octet-stream';
            $fileSize = Storage::disk('public')->size($fullPath);
            $lastModified = Storage::disk('public')->lastModified($fullPath);

            return response()->file($filePath, [
                'Content-Type' => $mimeType,
                'Content-Length' => (string) $fileSize,
                'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', $lastModified),
                'ETag' => '"' . md5_file($filePath) . '"',
                'x-amz-request-id' => Str::uuid()->toString(),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('InternalError', $e->getMessage(), 500);
        }
    }

    /**
     * Delete a file (DELETE)
     */
    public function delete(Request $request, string $bucket, string $path = '')
    {
        try {
            $path = trim($path, '/');

            if (empty($path)) {
                return $this->errorResponse('InvalidRequest', 'Path cannot be empty', 400);
            }

            // Full path includes bucket name: bucket-name/path/to/file
            $fullPath = $bucket . '/' . $path;

            if (!Storage::disk('public')->exists($fullPath)) {
                return $this->errorResponse('NoSuchKey', 'The specified key does not exist.', 404, $path);
            }

            Storage::disk('public')->delete($fullPath);

            // S3-compliant response
            return response('', 204)
                ->header('x-amz-request-id', Str::uuid()->toString());
        } catch (\Exception $e) {
            return $this->errorResponse('InternalError', $e->getMessage(), 500);
        }
    }

    /**
     * List files in a bucket (GET with prefix)
     */
    public function list(Request $request, string $bucket)
    {
        try {
            $prefix = trim($request->query('prefix', ''), '/');
            $delimiter = $request->query('delimiter', '/');
            $marker = trim($request->query('marker', ''), '/');
            $maxKeys = min((int) $request->query('max-keys', 1000), 1000);

            // Bucket path includes bucket name
            $bucketPath = $bucket;

            // Get all files in the bucket
            $allFiles = Storage::disk('public')->allFiles($bucketPath);

            // Filter by prefix
            $files = [];
            $commonPrefixes = [];
            $seenPrefixes = [];

            foreach ($allFiles as $file) {
                // Get relative path from bucket root (remove bucket name)
                $relativePath = str_replace($bucketPath . '/', '', $file);

                // Skip if doesn't match prefix
                if ($prefix && !str_starts_with($relativePath, $prefix)) {
                    continue;
                }

                // Skip if before marker
                if ($marker && $relativePath <= $marker) {
                    continue;
                }

                // Check if this path is already covered by a common prefix
                $isUnderPrefix = false;
                foreach ($seenPrefixes as $seenPrefix) {
                    if (str_starts_with($relativePath, $seenPrefix)) {
                        $isUnderPrefix = true;
                        break;
                    }
                }

                if ($isUnderPrefix) {
                    continue; // Skip files under common prefixes
                }

                // If delimiter is set, check if we should treat as common prefix
                if ($delimiter && str_contains($relativePath, $delimiter)) {
                    // Find the position after the prefix
                    $startPos = $prefix ? strlen($prefix) : 0;
                    // Find the next delimiter after the prefix
                    $delimiterPos = strpos($relativePath, $delimiter, $startPos);

                    if ($delimiterPos !== false) {
                        // Extract common prefix (everything up to and including the delimiter)
                        $commonPrefix = substr($relativePath, 0, $delimiterPos + strlen($delimiter));

                        if (!in_array($commonPrefix, $seenPrefixes)) {
                            $seenPrefixes[] = $commonPrefix;
                            $commonPrefixes[] = $commonPrefix;
                        }
                        continue; // Don't add as file, it's a prefix
                    }
                }

                // Treat as file (no delimiter or no delimiter after prefix)
                $files[] = [
                    'Key' => $relativePath,
                    'LastModified' => gmdate('Y-m-d\TH:i:s.000\Z', Storage::disk('public')->lastModified($file)),
                    'ETag' => '"' . md5_file(Storage::disk('public')->path($file)) . '"',
                    'Size' => Storage::disk('public')->size($file),
                    'StorageClass' => 'STANDARD',
                ];

                if (count($files) + count($commonPrefixes) >= $maxKeys) {
                    break;
                }
            }

            // Sort files and prefixes
            usort($files, fn($a, $b) => strcmp($a['Key'], $b['Key']));
            sort($commonPrefixes);

            return $this->buildListResponse($bucket, $prefix, $delimiter, $files, $commonPrefixes, $marker, $maxKeys);
        } catch (\Exception $e) {
            return $this->errorResponse('InternalError', $e->getMessage(), 500);
        }
    }

    /**
     * Build S3-compliant list response
     */
    private function buildListResponse(string $bucket, string $prefix, string $delimiter, array $files, array $commonPrefixes, string $marker, int $maxKeys)
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ListBucketResult></ListBucketResult>');
        $xml->addChild('Name', htmlspecialchars($bucket));
        if ($prefix) {
            $xml->addChild('Prefix', htmlspecialchars($prefix));
        }
        if ($marker) {
            $xml->addChild('Marker', htmlspecialchars($marker));
        }
        $xml->addChild('MaxKeys', (string) $maxKeys);
        if ($delimiter) {
            $xml->addChild('Delimiter', htmlspecialchars($delimiter));
        }
        $xml->addChild('IsTruncated', (count($files) + count($commonPrefixes) >= $maxKeys) ? 'true' : 'false');
        $xml->addChild('KeyCount', (string) (count($files) + count($commonPrefixes)));

        // Add common prefixes
        foreach ($commonPrefixes as $commonPrefix) {
            $prefixElement = $xml->addChild('CommonPrefixes');
            $prefixElement->addChild('Prefix', htmlspecialchars($commonPrefix));
        }

        // Add files
        foreach ($files as $file) {
            $content = $xml->addChild('Contents');
            $content->addChild('Key', htmlspecialchars($file['Key']));
            $content->addChild('LastModified', $file['LastModified']);
            $content->addChild('ETag', $file['ETag']);
            $content->addChild('Size', (string) $file['Size']);
            $content->addChild('StorageClass', $file['StorageClass']);
        }

        return response($xml->asXML(), 200)
            ->header('Content-Type', 'application/xml')
            ->header('x-amz-request-id', Str::uuid()->toString());
    }

    /**
     * Generate S3-compliant error response
     */
    private function errorResponse(string $code, string $message, int $statusCode, ?string $key = null)
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Error></Error>');
        $xml->addChild('Code', htmlspecialchars($code));
        $xml->addChild('Message', htmlspecialchars($message));
        if ($key) {
            $xml->addChild('Key', htmlspecialchars($key));
        }
        $xml->addChild('RequestId', Str::uuid()->toString());

        return response($xml->asXML(), $statusCode)
            ->header('Content-Type', 'application/xml')
            ->header('x-amz-request-id', Str::uuid()->toString());
    }
}
