# S3 Storage Clone API

A simplified S3-compatible storage API built with Laravel. This API allows you to create buckets, upload, retrieve, delete, and list files with S3-compliant responses.

## Features

- ✅ Upload files (PUT/POST) with streaming support
- ✅ Retrieve files (GET) with direct Apache serving for performance
- ✅ Delete files (DELETE)
- ✅ List bucket contents with prefix/delimiter support
- ✅ Bearer token authentication for write operations
- ✅ S3-compliant XML responses
- ✅ Automatic directory creation

## Setup

1. **Install dependencies:**
   ```bash
   composer install
   npm install
   ```

2. **Configure environment:**
   Create a `.env` file and set:
   ```env
   AUTH_BEARER=your-secret-bearer-token-here
   APP_URL=http://localhost:8000
   ```

3. **Create storage directory:**
   ```bash
   mkdir -p storage/app/buckets
   chmod -R 755 storage/app/buckets
   ```

4. **Start the server:**
   ```bash
   php artisan serve
   ```

## API Base URL

All endpoints are prefixed with `/api`:
```
http://localhost:8000/api
```

## Authentication

**Write operations** (PUT, POST, DELETE) require Bearer token authentication:
```
Authorization: Bearer your-secret-bearer-token-here
```

**Read operations** (GET) do not require authentication.

## API Endpoints

### 1. List Bucket Contents

List all files and folders in a bucket.

**Endpoint:** `GET /api/buckets/{bucket}`

**Query Parameters:**
- `prefix` (optional): Filter files by prefix
- `delimiter` (optional): Group files by delimiter (default: `/`)
- `marker` (optional): Pagination marker
- `max-keys` (optional): Maximum number of results (default: 1000, max: 1000)

**TypeScript Example:**
```typescript
interface ListBucketParams {
  prefix?: string;
  delimiter?: string;
  marker?: string;
  maxKeys?: number;
}

interface ListBucketResponse {
  ListBucketResult: {
    Name: string;
    Prefix?: string;
    Marker?: string;
    MaxKeys: string;
    Delimiter?: string;
    IsTruncated: string;
    KeyCount: string;
    Contents?: Array<{
      Key: string;
      LastModified: string;
      ETag: string;
      Size: string;
      StorageClass: string;
    }>;
    CommonPrefixes?: Array<{
      Prefix: string;
    }>;
  };
}

async function listBucket(
  bucket: string,
  params?: ListBucketParams
): Promise<ListBucketResponse> {
  const queryParams = new URLSearchParams();
  if (params?.prefix) queryParams.append('prefix', params.prefix);
  if (params?.delimiter) queryParams.append('delimiter', params.delimiter);
  if (params?.marker) queryParams.append('marker', params.marker);
  if (params?.maxKeys) queryParams.append('max-keys', params.maxKeys.toString());

  const url = `http://localhost:8000/api/buckets/${bucket}?${queryParams.toString()}`;
  
  const response = await fetch(url, {
    method: 'GET',
  });

  if (!response.ok) {
    throw new Error(`Failed to list bucket: ${response.statusText}`);
  }

  const xmlText = await response.text();
  // Parse XML to object (you may want to use a library like 'fast-xml-parser')
  return parseXML(xmlText);
}

// Usage
const result = await listBucket('my-bucket', {
  prefix: 'folder/',
  delimiter: '/',
  maxKeys: 100
});
```

### 2. Get File

Retrieve a file from a bucket.

**Endpoint:** `GET /api/buckets/{bucket}/{path}`

**TypeScript Example:**
```typescript
async function getFile(
  bucket: string,
  path: string
): Promise<Blob> {
  const url = `http://localhost:8000/api/buckets/${bucket}/${encodeURIComponent(path)}`;
  
  const response = await fetch(url, {
    method: 'GET',
  });

  if (!response.ok) {
    if (response.status === 404) {
      throw new Error('File not found');
    }
    throw new Error(`Failed to get file: ${response.statusText}`);
  }

  return await response.blob();
}

// Usage - Download file
const fileBlob = await getFile('my-bucket', 'path/to/file.mp4');

// Create download link
const url = URL.createObjectURL(fileBlob);
const a = document.createElement('a');
a.href = url;
a.download = 'file.mp4';
a.click();
URL.revokeObjectURL(url);
```

**Get File with Metadata:**
```typescript
async function getFileWithMetadata(
  bucket: string,
  path: string
): Promise<{ blob: Blob; metadata: Record<string, string> }> {
  const url = `http://localhost:8000/api/buckets/${bucket}/${encodeURIComponent(path)}`;
  
  const response = await fetch(url, {
    method: 'GET',
  });

  if (!response.ok) {
    throw new Error(`Failed to get file: ${response.statusText}`);
  }

  const blob = await response.blob();
  const metadata = {
    contentType: response.headers.get('Content-Type') || '',
    contentLength: response.headers.get('Content-Length') || '',
    lastModified: response.headers.get('Last-Modified') || '',
    etag: response.headers.get('ETag') || '',
  };

  return { blob, metadata };
}
```

### 3. Upload File (PUT)

Upload a file to a bucket using PUT method.

**Endpoint:** `PUT /api/buckets/{bucket}/{path}`

**Headers:**
- `Authorization: Bearer {token}` (required)
- `Content-Type: {mime-type}` (optional)

**TypeScript Example:**
```typescript
interface UploadFileOptions {
  contentType?: string;
  authToken: string;
}

async function uploadFile(
  bucket: string,
  path: string,
  file: File | Blob | ArrayBuffer,
  options: UploadFileOptions
): Promise<{ etag: string; requestId: string }> {
  const url = `http://localhost:8000/api/buckets/${bucket}/${encodeURIComponent(path)}`;
  
  let body: BodyInit;
  if (file instanceof File || file instanceof Blob) {
    body = file;
  } else {
    body = new Blob([file]);
  }

  const response = await fetch(url, {
    method: 'PUT',
    headers: {
      'Authorization': `Bearer ${options.authToken}`,
      ...(options.contentType && { 'Content-Type': options.contentType }),
    },
    body: body,
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(`Failed to upload file: ${response.statusText} - ${errorText}`);
  }

  return {
    etag: response.headers.get('ETag') || '',
    requestId: response.headers.get('x-amz-request-id') || '',
  };
}

// Usage - Upload from file input
const fileInput = document.querySelector<HTMLInputElement>('input[type="file"]');
if (fileInput?.files?.[0]) {
  const file = fileInput.files[0];
  const result = await uploadFile(
    'my-bucket',
    'path/to/file.mp4',
    file,
    {
      authToken: 'your-secret-bearer-token-here',
      contentType: file.type,
    }
  );
  console.log('Upload successful:', result);
}

// Usage - Upload from ArrayBuffer
const arrayBuffer = await file.arrayBuffer();
await uploadFile(
  'my-bucket',
  'path/to/file.mp4',
  arrayBuffer,
  {
    authToken: 'your-secret-bearer-token-here',
    contentType: 'video/mp4',
  }
);
```

**Upload with Progress:**
```typescript
async function uploadFileWithProgress(
  bucket: string,
  path: string,
  file: File,
  options: UploadFileOptions,
  onProgress?: (progress: number) => void
): Promise<{ etag: string; requestId: string }> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const url = `http://localhost:8000/api/buckets/${bucket}/${encodeURIComponent(path)}`;

    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable && onProgress) {
        const progress = (e.loaded / e.total) * 100;
        onProgress(progress);
      }
    });

    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve({
          etag: xhr.getResponseHeader('ETag') || '',
          requestId: xhr.getResponseHeader('x-amz-request-id') || '',
        });
      } else {
        reject(new Error(`Upload failed: ${xhr.statusText}`));
      }
    });

    xhr.addEventListener('error', () => {
      reject(new Error('Upload failed'));
    });

    xhr.open('PUT', url);
    xhr.setRequestHeader('Authorization', `Bearer ${options.authToken}`);
    if (options.contentType) {
      xhr.setRequestHeader('Content-Type', options.contentType);
    }
    xhr.send(file);
  });
}

// Usage with progress
await uploadFileWithProgress(
  'my-bucket',
  'path/to/large-file.mp4',
  file,
  { authToken: 'your-token' },
  (progress) => {
    console.log(`Upload progress: ${progress.toFixed(2)}%`);
  }
);
```

### 4. Upload File (POST)

Upload a file to a bucket using POST method (same as PUT).

**Endpoint:** `POST /api/buckets/{bucket}/{path}`

**TypeScript Example:**
```typescript
async function uploadFilePost(
  bucket: string,
  path: string,
  file: File | Blob,
  authToken: string
): Promise<{ etag: string; requestId: string }> {
  const url = `http://localhost:8000/api/buckets/${bucket}/${encodeURIComponent(path)}`;
  
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${authToken}`,
    },
    body: file,
  });

  if (!response.ok) {
    throw new Error(`Failed to upload file: ${response.statusText}`);
  }

  return {
    etag: response.headers.get('ETag') || '',
    requestId: response.headers.get('x-amz-request-id') || '',
  };
}
```

### 5. Delete File

Delete a file from a bucket.

**Endpoint:** `DELETE /api/buckets/{bucket}/{path}`

**TypeScript Example:**
```typescript
async function deleteFile(
  bucket: string,
  path: string,
  authToken: string
): Promise<void> {
  const url = `http://localhost:8000/api/buckets/${bucket}/${encodeURIComponent(path)}`;
  
  const response = await fetch(url, {
    method: 'DELETE',
    headers: {
      'Authorization': `Bearer ${authToken}`,
    },
  });

  if (!response.ok) {
    if (response.status === 404) {
      throw new Error('File not found');
    }
    throw new Error(`Failed to delete file: ${response.statusText}`);
  }
}

// Usage
await deleteFile('my-bucket', 'path/to/file.mp4', 'your-secret-bearer-token-here');
```

## Complete TypeScript Client Example

```typescript
class S3StorageClient {
  private baseUrl: string;
  private authToken: string;

  constructor(baseUrl: string, authToken: string) {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.authToken = authToken;
  }

  private async request(
    method: string,
    endpoint: string,
    options: {
      body?: BodyInit;
      headers?: Record<string, string>;
      requireAuth?: boolean;
    } = {}
  ): Promise<Response> {
    const url = `${this.baseUrl}${endpoint}`;
    const headers: Record<string, string> = {
      ...options.headers,
    };

    if (options.requireAuth) {
      headers['Authorization'] = `Bearer ${this.authToken}`;
    }

    const response = await fetch(url, {
      method,
      headers,
      body: options.body,
    });

    return response;
  }

  async listBucket(
    bucket: string,
    params?: {
      prefix?: string;
      delimiter?: string;
      marker?: string;
      maxKeys?: number;
    }
  ): Promise<string> {
    const queryParams = new URLSearchParams();
    if (params?.prefix) queryParams.append('prefix', params.prefix);
    if (params?.delimiter) queryParams.append('delimiter', params.delimiter);
    if (params?.marker) queryParams.append('marker', params.marker);
    if (params?.maxKeys) queryParams.append('max-keys', params.maxKeys.toString());

    const response = await this.request(
      'GET',
      `/api/buckets/${bucket}?${queryParams.toString()}`
    );

    if (!response.ok) {
      throw new Error(`Failed to list bucket: ${response.statusText}`);
    }

    return await response.text();
  }

  async getFile(bucket: string, path: string): Promise<Blob> {
    const response = await this.request(
      'GET',
      `/api/buckets/${bucket}/${encodeURIComponent(path)}`
    );

    if (!response.ok) {
      throw new Error(`Failed to get file: ${response.statusText}`);
    }

    return await response.blob();
  }

  async uploadFile(
    bucket: string,
    path: string,
    file: File | Blob | ArrayBuffer,
    contentType?: string
  ): Promise<{ etag: string; requestId: string }> {
    let body: BodyInit;
    if (file instanceof File || file instanceof Blob) {
      body = file;
    } else {
      body = new Blob([file]);
    }

    const headers: Record<string, string> = {};
    if (contentType) {
      headers['Content-Type'] = contentType;
    }

    const response = await this.request('PUT', `/api/buckets/${bucket}/${encodeURIComponent(path)}`, {
      body,
      headers,
      requireAuth: true,
    });

    if (!response.ok) {
      throw new Error(`Failed to upload file: ${response.statusText}`);
    }

    return {
      etag: response.headers.get('ETag') || '',
      requestId: response.headers.get('x-amz-request-id') || '',
    };
  }

  async deleteFile(bucket: string, path: string): Promise<void> {
    const response = await this.request(
      'DELETE',
      `/api/buckets/${bucket}/${encodeURIComponent(path)}`,
      { requireAuth: true }
    );

    if (!response.ok) {
      throw new Error(`Failed to delete file: ${response.statusText}`);
    }
  }
}

// Usage
const client = new S3StorageClient(
  'http://localhost:8000',
  'your-secret-bearer-token-here'
);

// List files
const xmlResponse = await client.listBucket('my-bucket', {
  prefix: 'folder/',
  delimiter: '/',
});

// Get file
const fileBlob = await client.getFile('my-bucket', 'path/to/file.mp4');

// Upload file
const file = new File(['content'], 'file.txt', { type: 'text/plain' });
const uploadResult = await client.uploadFile('my-bucket', 'path/to/file.txt', file);

// Delete file
await client.deleteFile('my-bucket', 'path/to/file.txt');
```

## Error Responses

All error responses follow S3 XML format:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Error>
  <Code>ErrorCode</Code>
  <Message>Error message</Message>
  <Key>path/to/file</Key>
  <RequestId>uuid</RequestId>
</Error>
```

**Common Error Codes:**
- `InvalidToken` - Bearer token is missing or invalid (403)
- `NoSuchKey` - File not found (404)
- `InvalidRequest` - Invalid request parameters (400)
- `InternalError` - Server error (500)

## File Storage Structure

Files are stored in:
```
storage/app/buckets/{bucket-name}/{path/to/file}
```

Example:
- Request: `PUT /api/buckets/my-bucket/videos/movie.mp4`
- Storage: `storage/app/buckets/my-bucket/videos/movie.mp4`

## Performance Notes

- **GET requests** are optimized to be served directly by Apache via `.htaccess` when possible, bypassing PHP for better performance
- **Uploads** use streaming for efficient memory usage
- Directories are automatically created as needed

## License

MIT
