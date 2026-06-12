<?php

namespace App\Services;

class ImageValidationService
{
    private const MIN_RESOLUTION_MP = 2;
    private const MAX_RESOLUTION_MP = 8.5;
    private const MIN_FILE_SIZE = 80 * 1024;
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;
    private const TARGET_ASPECT_RATIO = 16 / 9;
    private const ASPECT_RATIO_TOLERANCE = 0.02;

    public function validate(string $filePath, string $mimeType, int $fileSize): array
    {
        $issues = [];

        if (!str_starts_with($mimeType, 'image/')) {
            return ['issues' => [], 'width' => null, 'height' => null, 'status' => 'ok'];
        }

        [$width, $height] = $this->getImageDimensions($filePath);

        if ($width && $height) {
            $issues = array_merge(
                $issues,
                $this->checkResolution($width, $height),
                $this->checkAspectRatio($width, $height)
            );
        } else {
            $issues[] = 'Unreadable or corrupted image — dimensions could not be determined';
        }

        $issues = array_merge(
            $issues,
            $this->checkFileSize($fileSize)
        );

        $status = empty($issues) ? 'ok' : 'warning';

        return [
            'issues' => $issues,
            'width' => $width,
            'height' => $height,
            'status' => $status,
        ];
    }

    private function getImageDimensions(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [null, null];
        }

        $imageInfo = @getimagesize($filePath);
        if ($imageInfo === false) {
            return [null, null];
        }

        return [$imageInfo[0], $imageInfo[1]];
    }

    private function checkResolution(int $width, int $height): array
    {
        $megapixels = ($width * $height) / 1_000_000;
        $issues = [];

        if ($megapixels < self::MIN_RESOLUTION_MP) {
            $issues[] = "Low resolution ({$megapixels}MP) — image may appear pixelated";
        }

        if ($megapixels > self::MAX_RESOLUTION_MP) {
            $issues[] = "High resolution ({$megapixels}MP) — may degrade slideshow performance";
        }

        return $issues;
    }

    private function checkFileSize(int $fileSize): array
    {
        $issues = [];
        $sizeMB = $fileSize / (1024 * 1024);

        if ($fileSize < self::MIN_FILE_SIZE) {
            $issues[] = "File too small — likely poor image quality";
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            $issues[] = "File too large (over 5MB) — may cause performance issues";
        }

        return $issues;
    }

    private function checkAspectRatio(int $width, int $height): array
    {
        $ratio = $width / $height;
        $targetRatio = self::TARGET_ASPECT_RATIO;
        $tolerance = $targetRatio * self::ASPECT_RATIO_TOLERANCE;

        $minRatio = $targetRatio - $tolerance;
        $maxRatio = $targetRatio + $tolerance;

        if ($ratio < $minRatio || $ratio > $maxRatio) {
            $actualRatio = number_format($ratio, 2);
            return ["Aspect ratio {$actualRatio} (16:9 ± 2% recommended) — may cause letterboxing"];
        }

        return [];
    }
}
