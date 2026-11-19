<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Looks inside an uploaded audio file with ffprobe: is there really an audio
 * stream, and how long is it. Used when a file is adopted into the library,
 * so a renamed video or random bytes can't become "music".
 */
class AudioProbe
{
    /**
     * @return array{playable: bool, duration: ?int}|null null when ffprobe
     *         isn't available (the file is then accepted on its declared type)
     */
    public function inspect(string $path): ?array
    {
        $process = new Process([
            config('slides.ffprobe_binary'), '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=codec_type:format=duration',
            '-of', 'json', $path,
        ]);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }
        if ($process->getExitCode() === 127) {
            return null; // binary not found
        }
        if (!$process->isSuccessful()) {
            return ['playable' => false, 'duration' => null];
        }

        $info = json_decode($process->getOutput(), true) ?: [];
        $duration = $info['format']['duration'] ?? null;

        return [
            'playable' => !empty($info['streams']),
            'duration' => is_numeric($duration) ? (int) round((float) $duration) : null,
        ];
    }
}
