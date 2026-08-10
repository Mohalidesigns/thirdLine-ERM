<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadService
{
    private array $allowedTypes = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    private int $maxSizeBytes = 10485760; // 10MB

    /**
     * Upload a file with validation and organization
     */
    public function upload(UploadedFile $file, string $entityType, int $entityId): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! array_key_exists($extension, $this->allowedTypes)) {
            throw new \InvalidArgumentException(
                "File type .{$extension} is not allowed. Allowed types: ".implode(', ', array_keys($this->allowedTypes))
            );
        }

        if ($file->getSize() > $this->maxSizeBytes) {
            throw new \InvalidArgumentException('File size exceeds maximum of 10MB.');
        }

        $fileName = Str::uuid().'.'.$extension;
        $path = "attachments/{$entityType}/{$entityId}";
        $storagePath = $file->storeAs($path, $fileName, 'local');

        return [
            'file_name' => $file->getClientOriginalName(),
            'file_size_bytes' => $file->getSize(),
            'file_type' => $extension,
            'storage_path' => $storagePath,
            'uuid' => Str::uuid()->toString(),
            'uploaded_at' => now(),
        ];
    }

    /**
     * Download a file from storage
     */
    public function download(string $storagePath): string
    {
        if (! Storage::disk('local')->exists($storagePath)) {
            throw new \RuntimeException('File not found.');
        }

        return Storage::disk('local')->path($storagePath);
    }

    /**
     * Delete a file from storage
     */
    public function delete(string $storagePath): bool
    {
        return Storage::disk('local')->delete($storagePath);
    }

    /**
     * Get file info from storage
     */
    public function getFileInfo(string $storagePath): array
    {
        if (! Storage::disk('local')->exists($storagePath)) {
            throw new \RuntimeException('File not found.');
        }

        return [
            'exists' => true,
            'size' => Storage::disk('local')->size($storagePath),
            'last_modified' => Storage::disk('local')->lastModified($storagePath),
            'mime_type' => Storage::disk('local')->mimeType($storagePath),
        ];
    }

    /**
     * Get list of allowed file types
     */
    public function getAllowedTypes(): array
    {
        return array_keys($this->allowedTypes);
    }

    /**
     * Get max file size in MB
     */
    public function getMaxSizeMb(): int
    {
        return (int) ($this->maxSizeBytes / 1024 / 1024);
    }
}
