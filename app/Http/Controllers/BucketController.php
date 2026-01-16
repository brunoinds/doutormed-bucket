<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BucketController extends Controller
{
    /**
     * Generate a signed URL for uploading a file
     * Returns a URL that can be used to upload directly from the front-end
     */
    public function generateSignedUrl(Request $request, string $bucket, string $path = '')
    {
        try {
            $request->validate([
                'expires' => 'nullable|integer|min:1|max:604800', // Max 7 days in seconds
            ]);

            // Normalize the path (remove leading/trailing slashes)
            $path = trim($path, '/');

            if (empty($path)) {
                return response()->json([
                    'error' => 'Path cannot be empty'
                ], 400);
            }

            // Default expiration: 1 hour (3600 seconds)
            $expiresIn = $request->input('expires', 3600);
            $expiresAt = Carbon::now()->addSeconds($expiresIn);

            // Create the payload to sign
            $payload = [
                'bucket' => $bucket,
                'path' => $path,
                'expires' => $expiresAt->timestamp,
            ];

            // Generate signature using app key
            $signature = $this->generateSignature($payload);

            // Build the signed URL - encode path segments but preserve slashes
            $pathSegments = explode('/', $path);
            $encodedPath = implode('/', array_map('rawurlencode', $pathSegments));
            $uploadUrl = URL::to("/api/buckets/{$bucket}/{$encodedPath}");
            $signedUrl = $uploadUrl . '?' . http_build_query([
                'signature' => $signature,
                'expires' => $expiresAt->timestamp,
            ]);

            return response()->json([
                'url' => $signedUrl,
                'expires_at' => $expiresAt->toIso8601String(),
                'expires_in' => $expiresIn,
                'method' => 'PUT',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Internal error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate signature for signed URL
     */
    private function generateSignature(array $payload): string
    {
        $appKey = config('app.key');
        if (empty($appKey)) {
            throw new \Exception('APP_KEY is not configured');
        }

        // Create a string representation of the payload
        $data = json_encode($payload, JSON_UNESCAPED_SLASHES);

        // Generate HMAC signature
        $signature = hash_hmac('sha256', $data, $appKey);

        return $signature;
    }

    /**
     * Verify signature for signed URL
     */
    private function verifySignature(string $signature, array $payload): bool
    {
        try {
            $expectedSignature = $this->generateSignature($payload);
            return hash_equals($expectedSignature, $signature);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Upload or put a file (POST/PUT)
     * S3-compliant endpoint
     * Accepts either Bearer token authentication or signed URL
     */
    /* public function put(Request $request, string $bucket, string $path = '')
    {
        set_time_limit(0);
        ini_set('memory_limit', '1G');
        ini_set('upload_max_filesize', '512M');
        ini_set('post_max_size', '512M');
        ini_set('max_execution_time', '300');
        ini_set('max_input_time', '300');
        ini_set('max_input_vars', '1048576');
        try {
            // Check if this is a signed URL request
            $signature = $request->query('signature');
            $expires = $request->query('expires');

            if ($signature && $expires) {
                // Verify signed URL
                $expiresTimestamp = (int) $expires;

                // Check if expired
                if ($expiresTimestamp < time()) {
                    return $this->errorResponse('ExpiredToken', 'The provided token has expired', 403);
                }

                // Normalize the path (remove leading/trailing slashes)
                $path = trim($path, '/');

                if (empty($path)) {
                    return $this->errorResponse('InvalidRequest', 'Path cannot be empty', 400);
                }

                // Verify signature
                $payload = [
                    'bucket' => $bucket,
                    'path' => $path,
                    'expires' => $expiresTimestamp,
                ];

                if (!$this->verifySignature($signature, $payload)) {
                    return $this->errorResponse('InvalidToken', 'The provided token is invalid', 403);
                }
            } else {
                // Normalize the path (remove leading/trailing slashes)
                $path = trim($path, '/');

                if (empty($path)) {
                    return $this->errorResponse('InvalidRequest', 'Path cannot be empty', 400);
                }
            }

            // Full path includes bucket name: bucket-name/path/to/file
            $fullPath = 'buckets/' . $bucket . '/' . $path;

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
    } */

    public function put(Request $request, string $bucket, string $path = '')
    {
        set_time_limit(0);
        ini_set('memory_limit', '1G');
        ini_set('upload_max_filesize', '512M');
        ini_set('post_max_size', '512M');
        ini_set('max_execution_time', '300');
        ini_set('max_input_time', '300');
        ini_set('max_input_vars', '1048576');
        try {
            // Check if this is a signed URL request
            $signature = $request->query('signature');
            $expires = $request->query('expires');

            if ($signature && $expires) {
                // Verify signed URL
                $expiresTimestamp = (int) $expires;

                // Check if expired
                if ($expiresTimestamp < time()) {
                    return $this->errorResponse('ExpiredToken', 'The provided token has expired', 403);
                }

                // Normalize the path (remove leading/trailing slashes)
                $path = trim($path, '/');

                if (empty($path)) {
                    return $this->errorResponse('InvalidRequest', 'Path cannot be empty', 400);
                }

                // Verify signature
                $payload = [
                    'bucket' => $bucket,
                    'path' => $path,
                    'expires' => $expiresTimestamp,
                ];

                if (!$this->verifySignature($signature, $payload)) {
                    return $this->errorResponse('InvalidToken', 'The provided token is invalid', 403);
                }
            } else {
                // Normalize the path (remove leading/trailing slashes)
                $path = trim($path, '/');

                if (empty($path)) {
                    return $this->errorResponse('InvalidRequest', 'Path cannot be empty', 400);
                }
            }

            // Full path includes bucket name: bucket-name/path/to/file
            $fullPath = 'buckets/' . $bucket . '/' . $path;

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
            $fullPath = 'buckets/' . $bucket . '/' . $path;

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
     * Check if an object exists (HEAD)
     * S3-compliant endpoint - returns metadata without body
     */
    public function head(Request $request, string $bucket, string $path = '')
    {
        try {
            $path = trim($path, '/');

            if (empty($path)) {
                return $this->errorResponse('InvalidRequest', 'Path cannot be empty', 400);
            }

            // Full path includes bucket name: bucket-name/path/to/file
            $fullPath = 'buckets/' . $bucket . '/' . $path;

            if (!Storage::disk('public')->exists($fullPath)) {
                return $this->errorResponse('NoSuchKey', 'The specified key does not exist.', 404, $path);
            }

            $filePath = Storage::disk('public')->path($fullPath);
            $mimeType = Storage::disk('public')->mimeType($fullPath) ?: 'application/octet-stream';
            $fileSize = Storage::disk('public')->size($fullPath);
            $lastModified = Storage::disk('public')->lastModified($fullPath);

            // S3-compliant HEAD response - headers only, no body
            return response('', 200)
                ->header('Content-Type', $mimeType)
                ->header('Content-Length', (string) $fileSize)
                ->header('Last-Modified', gmdate('D, d M Y H:i:s \G\M\T', $lastModified))
                ->header('ETag', '"' . md5_file($filePath) . '"')
                ->header('x-amz-request-id', Str::uuid()->toString());
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
            $fullPath = 'buckets/' . $bucket . '/' . $path;

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
    public function listBucket(Request $request, string $bucket)
    {
        try {
            $prefix = trim($request->query('prefix', ''), '/');
            $delimiter = $request->query('delimiter', '/');
            $marker = trim($request->query('marker', ''), '/');
            $maxKeys = min((int) $request->query('max-keys', 1000), 1000);

            // Bucket path includes bucket name
            $bucketPath = 'buckets/' . $bucket;

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

    public function listPrefix(Request $request, string $bucket, string $path = '')
    {
        try {
            $path = trim($path, '/');

            if (empty($path)) {
                return $this->errorResponse('InvalidRequest', 'Path cannot be empty', 400);
            }

            // Full path includes bucket name: bucket-name/path/to/file
            $fullPath = 'buckets/' . $bucket . '/' . $path;

            if (!Storage::disk('public')->exists($fullPath)) {
                return $this->errorResponse('NoSuchKey', 'The specified key does not exist.', 404, $path);
            }

            // Get all files in the bucket
            $allFiles = Storage::disk('public')->allFiles($fullPath);

            $prefix = trim($request->query('prefix', ''), '/');
            $delimiter = $request->query('delimiter', '/');
            $marker = trim($request->query('marker', ''), '/');
            $maxKeys = min((int) $request->query('max-keys', 1000), 1000);

            // Filter by prefix
            $files = [];
            $commonPrefixes = [];
            $seenPrefixes = [];

            foreach ($allFiles as $file) {
                // Get relative path from bucket root (remove bucket name)
                $relativePath = str_replace($fullPath . '/', '', $file);

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
     * Show the file uploader page
     */
    public function showUploader()
    {
        return view('uploader');
    }

    /**
     * Handle file upload from web form
     */
    public function uploadFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'bucket' => 'required|string|max:255',
            'path' => 'nullable|string|max:1000',
        ]);

        try {
            $file = $request->file('file');
            $bucket = $request->input('bucket');
            $path = $request->input('path', '');

            // Normalize the path
            $path = trim($path, '/');

            // If no path provided, use the original filename
            if (empty($path)) {
                $path = $file->getClientOriginalName();
            }

            // Full path includes bucket name: bucket-name/path/to/file
            $fullPath = 'buckets/' . $bucket . '/' . $path;

            // Create directory structure if it doesn't exist
            $directory = dirname($fullPath);
            if ($directory !== '.' && $directory !== '') {
                Storage::disk('public')->makeDirectory($directory);
            }

            // Store the file using Storage facade
            $storedPath = Storage::disk('public')->putFileAs(
                dirname($fullPath) === '.' ? '' : dirname($fullPath),
                $file,
                basename($fullPath)
            );

            if ($storedPath) {
                return redirect()->route('uploader')
                    ->with('success', 'File uploaded successfully!')
                    ->with('file_path', $storedPath);
            } else {
                return redirect()->route('uploader')
                    ->with('error', 'Failed to upload file.');
            }
        } catch (\Exception $e) {
            return redirect()->route('uploader')
                ->with('error', 'Error: ' . $e->getMessage());
        }
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
