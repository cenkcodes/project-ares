<?php

namespace App\Services\VideoHealth;

use InvalidArgumentException;
use RuntimeException;

class VideoHealthArtifacts
{
    private const JSONL = ['catalog.jsonl', 'results.jsonl', 'reconfirmation.jsonl', 'db-backup.jsonl'];
    private const ARTIFACTS = [...self::JSONL, 'summary.json', 'checksums.sha256'];
    private const FINALIZED = '.finalized.json';
    private const READABLE_JSONL = ['catalog.jsonl', 'results.jsonl', 'reconfirmation.jsonl'];
    private const MAX_JSONL_RECORD_BYTES = 1048576;
    private readonly string $directory;

    public function __construct(int|string $runId, ?string $root = null)
    {
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $runId)) {
            throw new InvalidArgumentException('Invalid run ID.');
        }
        $root ??= storage_path('app/private/video-health/runs');
        if (str_contains($root, "\0") || !preg_match('~^(?:/|[a-zA-Z]:[\\\\/])~', $root)
            || preg_match('~(?:^|[\\\\/])\.{1,2}(?:[\\\\/]|$)~', $root)) {
            throw new InvalidArgumentException('Artifact root must be an absolute path without traversal.');
        }
        $this->ensureDirectory($root);
        $resolved = realpath($root);
        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve artifact root.');
        }
        $this->directory = $resolved.DIRECTORY_SEPARATOR.$runId;
        $this->ensureDirectory($this->directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function initializeEmptyJsonl(string $name): void
    {
        if (!in_array($name, self::READABLE_JSONL, true)) {
            throw new InvalidArgumentException('Unknown empty JSONL artifact.');
        }
        $this->locked(function () use ($name): void {
            $this->assertMutable($name);
            // Exclusive creation refuses even an existing zero-byte evidence file.
            $file = $this->open($this->path($name), exclusiveCreate: true);
            try {
                if (!flock($file, LOCK_EX)) {
                    throw new RuntimeException('Cannot lock empty artifact.');
                }
                $this->flush($file);
            } finally {
                flock($file, LOCK_UN);
                fclose($file);
            }
        });
    }

    /**
     * Stream object records without changing evidence or finalization state.
     * The shared file lock lasts until exhaustion or generator destruction.
     * Release a retained generator after stopping early (for example, unset it).
     * This reader does not establish checksum validity or finalization.
     *
     * @return \Generator<int, array>
     */
    public function streamJsonl(string $name): \Generator
    {
        if (!in_array($name, self::READABLE_JSONL, true)) {
            throw new InvalidArgumentException('Unknown readable JSONL artifact.');
        }
        $file = $this->open($this->path($name), true, false);
        try {
            if (!flock($file, LOCK_SH)) {
                throw new RuntimeException('Cannot lock readable artifact: '.$name.'.');
            }
            $lineNumber = 0;
            // Read at most the limit plus one byte, so oversize detection is bounded.
            while (($line = fgets($file, self::MAX_JSONL_RECORD_BYTES + 2)) !== false) {
                $lineNumber++;
                $context = $name.' at line '.$lineNumber;
                if (strlen($line) > self::MAX_JSONL_RECORD_BYTES) {
                    throw new RuntimeException('JSONL record exceeds size limit: '.$context.'.');
                }
                if (!str_ends_with($line, "\n")) {
                    throw new RuntimeException('Incomplete JSONL record: '.$context.'.');
                }
                try {
                    $row = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new RuntimeException('Invalid JSONL record: '.$context.'.', 0, $exception);
                }
                if (!is_object($row)) {
                    throw new RuntimeException('JSONL record must be an object: '.$context.'.');
                }
                // Decode nested objects as associative arrays too; {} remains a valid row.
                yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
            if (!feof($file)) {
                throw new RuntimeException('Cannot read JSONL artifact: '.$name.' after line '.$lineNumber.'.');
            }
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    public function appendJsonl(string $name, array|object $row): void
    {
        $this->requireJsonl($name);
        if (is_array($row) && $row !== [] && array_is_list($row)) {
            throw new InvalidArgumentException('JSONL rows must be objects.');
        }
        $line = json_encode((object) $row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $this->locked(function () use ($name, $line): void {
            $this->assertMutable($name);
            $file = $this->open($this->path($name));
            try {
                if (!flock($file, LOCK_EX) || fseek($file, 0, SEEK_END) !== 0) {
                    throw new RuntimeException('Cannot lock or seek artifact.');
                }
                $offset = ftell($file);
                if ($offset === false) {
                    throw new RuntimeException('Cannot determine append position.');
                }
                try {
                    $this->writeAll($file, $line);
                    $this->flush($file);
                } catch (\Throwable $exception) {
                    if (!ftruncate($file, $offset) || !fflush($file)) {
                        throw new RuntimeException('Failed write left an incomplete artifact.', 0, $exception);
                    }
                    throw $exception;
                }
            } finally {
                flock($file, LOCK_UN);
                fclose($file);
            }
        });
    }

    public function startBackup(bool $reset = false): void
    {
        $this->locked(function () use ($reset): void {
            $this->assertMutable('db-backup.jsonl');
            $path = $this->path('db-backup.jsonl');
            $this->checkFile($path);
            if (file_exists($path) && !$reset) {
                throw new RuntimeException('Backup exists; reset must be explicit.');
            }
            $file = $this->open($path);
            try {
                if (!flock($file, LOCK_EX) || !ftruncate($file, 0)) {
                    throw new RuntimeException('Cannot reset backup.');
                }
                $this->flush($file);
            } finally {
                flock($file, LOCK_UN);
                fclose($file);
            }
        });
    }

    public function appendBackup(array|object $row): void
    {
        $this->checkFile($this->path('db-backup.jsonl'), true);
        $this->appendJsonl('db-backup.jsonl', $row);
    }

    public function finalizeBackup(): array
    {
        return $this->finalizeJsonl('db-backup.jsonl');
    }

    public function finalizeJsonl(string $name): array
    {
        $this->requireJsonl($name);

        return $this->locked(function () use ($name): array {
            $hashes = $this->manifest();
            $hash = $this->hash($name);
            if (isset($hashes[$name]) && !hash_equals($hashes[$name], $hash)) {
                throw new RuntimeException('Finalized artifact was modified.');
            }
            $file = $this->open($this->path($name), true);
            $rows = 0;
            try {
                while (($line = fgets($file)) !== false) {
                    if (!str_ends_with($line, "\n") || !is_object(json_decode($line, false, 512, JSON_THROW_ON_ERROR))) {
                        throw new RuntimeException('Incomplete or invalid JSONL row.');
                    }
                    $rows++;
                }
                if (!feof($file)) {
                    throw new RuntimeException('Cannot read artifact.');
                }
            } finally {
                fclose($file);
            }
            $hashes[$name] = $hash;
            $this->saveManifest($hashes);

            return ['row_count' => $rows, 'sha256' => $hash];
        });
    }

    public function writeSummary(array|object $summary): void
    {
        $json = json_encode((object) $summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $this->locked(function () use ($json): void {
            $this->assertMutable('summary.json');
            $path = $this->path('summary.json');
            $this->checkFile($path);
            if (file_exists($path)) {
                throw new RuntimeException('Summary already exists.');
            }
            $this->atomicWrite('summary.json', $json);
            $hashes = $this->manifest();
            $hashes['summary.json'] = $this->hash('summary.json');
            $this->saveManifest($hashes);
            $this->finalizeSummaryLocked();
        });
    }

    public function writeProvisionalSummary(array|object $summary): void
    {
        $json = json_encode((object) $summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $this->locked(function () use ($json): void {
            $this->assertMutable('summary.json');
            $this->atomicWrite('summary.json', $json);
        });
    }

    public function finalizeSummary(): string
    {
        return $this->locked(fn (): string => $this->finalizeSummaryLocked());
    }

    private function finalizeSummaryLocked(): string
    {
        $hash = $this->hash('summary.json');
        $this->verifyManifest();
        $manifest = $this->manifest();
        if (!isset($manifest['summary.json']) || !hash_equals($manifest['summary.json'], $hash)) {
            throw new RuntimeException('Summary must have a verified checksum before finalization.');
        }
        $finalized = $this->finalized();
        if (!isset($finalized['summary.json'])) {
            $finalized['summary.json'] = $hash;
            ksort($finalized, SORT_STRING);
            $this->atomicWrite(self::FINALIZED, json_encode((object) $finalized, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }

        return $hash;
    }

    public function sha256(string $name): string
    {
        return $this->locked(fn (): string => $this->hash($name));
    }

    public function verify(string $name, string $expected): bool
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/i', $expected) || !hash_equals(strtolower($expected), $this->sha256($name))) {
            throw new RuntimeException('Artifact checksum mismatch.');
        }

        return true;
    }

    public function generateChecksumManifest(array $names): void
    {
        $this->locked(function () use ($names): void {
            $hashes = $this->manifest();
            $finalized = $this->finalized();
            foreach ($names as $name) {
                if (!is_string($name) || $name === 'checksums.sha256') {
                    throw new InvalidArgumentException('Invalid checksum artifact.');
                }
                $hash = $this->hash($name);
                if (isset($hashes[$name]) && !hash_equals($hashes[$name], $hash)
                    && ($name !== 'summary.json' || isset($finalized[$name]))) {
                    throw new RuntimeException('Finalized artifact was modified.');
                }
                $hashes[$name] = $hash;
            }
            $this->saveManifest($hashes);
        });
    }

    public function verifyChecksumManifest(): bool
    {
        return $this->locked(fn (): bool => $this->verifyManifest());
    }

    private function verifyManifest(): bool
    {
        $this->checkFile($this->path('checksums.sha256'), true);
        $hashes = $this->manifest();
        if ($hashes === []) {
            throw new RuntimeException('Checksum manifest is empty.');
        }
        foreach ($this->finalized() as $name => $expected) {
            if (!isset($hashes[$name]) || !hash_equals($expected, $hashes[$name])) {
                throw new RuntimeException('Checksum manifest disagrees with finalized state.');
            }
        }
        foreach ($hashes as $name => $expected) {
            if (!hash_equals($expected, $this->hash($name))) {
                throw new RuntimeException('Artifact checksum mismatch.');
            }
        }

        return true;
    }

    private function path(string $name): string
    {
        if (!in_array($name, self::ARTIFACTS, true)) {
            throw new InvalidArgumentException('Unknown artifact name.');
        }

        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }

    private function requireJsonl(string $name): void
    {
        if (!in_array($name, self::JSONL, true)) {
            throw new InvalidArgumentException('Unknown JSONL artifact.');
        }
    }

    private function ensureDirectory(string $path, bool $create = true): void
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('Symlink directory rejected.');
        }
        $parent = dirname($path);
        if ($parent !== $path) {
            $this->ensureDirectory($parent, $create);
        }
        if (file_exists($path)) {
            if (!is_dir($path)) {
                throw new RuntimeException('Unexpected directory object.');
            }
        } elseif (!$create) {
            throw new RuntimeException('Artifact directory does not exist.');
        } elseif (!@mkdir($path, 0700) && !is_dir($path)) {
            throw new RuntimeException('Cannot create artifact directory.');
        }
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path)) {
            throw new RuntimeException('Unsafe artifact directory.');
        }
    }

    private function checkFile(string $path, bool $required = false): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (is_link($path) || ($stat !== false && (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1))) {
            throw new RuntimeException('Unsafe artifact file.');
        }
        if ($required && $stat === false) {
            throw new RuntimeException('Artifact does not exist.');
        }
    }

    private function open(string $path, bool $readOnly = false, bool $createDirectory = true, bool $exclusiveCreate = false)
    {
        $this->ensureDirectory($this->directory, $createDirectory);
        $this->checkFile($path, $readOnly);
        $exists = file_exists($path);
        if ($exclusiveCreate && $exists) {
            throw new RuntimeException('Artifact already exists.');
        }
        $file = @fopen($path, $exclusiveCreate ? 'x+b' : ($readOnly ? 'rb' : ($exists ? 'c+b' : 'x+b')));
        if ($file === false) {
            throw new RuntimeException('Cannot open artifact.');
        }
        try {
            $this->checkFile($path, true);
            $opened = fstat($file);
            $current = lstat($path);
            if ($opened === false || $current === false || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']) {
                throw new RuntimeException('Artifact changed while opening.');
            }
            if (!$exists && PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0600)) {
                throw new RuntimeException('Cannot secure artifact permissions.');
            }
        } catch (\Throwable $exception) {
            fclose($file);
            throw $exception;
        }

        return $file;
    }

    private function locked(callable $operation): mixed
    {
        // Internal synchronization file; never included in the artifact/checksum allowlist.
        $lock = $this->open($this->directory.DIRECTORY_SEPARATOR.'.lock');
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock run directory.');
            }

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeAll($file, string $contents): void
    {
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = @fwrite($file, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Artifact write failed.');
            }
            $offset += $written;
        }
    }

    private function flush($file): void
    {
        if (!fflush($file) || (function_exists('fsync') && !fsync($file))) {
            throw new RuntimeException('Artifact flush failed.');
        }
    }

    private function atomicWrite(string $name, string $contents): void
    {
        $path = $name === self::FINALIZED ? $this->finalizedPath() : $this->path($name);
        $this->checkFile($path);
        $temporary = $this->directory.DIRECTORY_SEPARATOR.'.'.$name.'.'.bin2hex(random_bytes(12)).'.tmp';
        $file = $this->open($temporary);
        try {
            $this->writeAll($file, $contents);
            $this->flush($file);
        } catch (\Throwable $exception) {
            fclose($file);
            @unlink($temporary);
            throw $exception;
        }
        fclose($file);
        try {
            $this->ensureDirectory($this->directory);
            $this->checkFile($temporary, true);
            $this->checkFile($path);
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Atomic artifact replacement failed.');
            }
        } finally {
            if (is_file($temporary) && !is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function hash(string $name): string
    {
        $file = $this->open($this->path($name), true);
        try {
            $context = hash_init('sha256');
            if (hash_update_stream($context, $file) === false || !feof($file)) {
                throw new RuntimeException('Cannot hash artifact.');
            }

            return hash_final($context);
        } finally {
            fclose($file);
        }
    }

    private function manifest(): array
    {
        $path = $this->path('checksums.sha256');
        $this->checkFile($path);
        if (!file_exists($path)) {
            return [];
        }
        $file = $this->open($path, true);
        $hashes = [];
        try {
            while (($line = fgets($file)) !== false) {
                if (!preg_match('/\A([a-f0-9]{64})  ([a-z.-]+)\n\z/', $line, $matches)
                    || $matches[2] === 'checksums.sha256' || isset($hashes[$matches[2]])) {
                    throw new RuntimeException('Invalid checksum manifest.');
                }
                $this->path($matches[2]);
                $hashes[$matches[2]] = $matches[1];
            }
            if (!feof($file)) {
                throw new RuntimeException('Cannot read checksum manifest.');
            }
        } finally {
            fclose($file);
        }

        return $hashes;
    }

    private function assertMutable(string $name): void
    {
        // Check authoritative metadata first, even when checksums are absent or corrupt.
        if (isset($this->finalized()[$name]) || ($name !== 'summary.json' && isset($this->manifest()[$name]))) {
            throw new RuntimeException('Artifact is finalized.');
        }
    }

    private function finalizedPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.self::FINALIZED;
    }

    private function finalized(): array
    {
        $path = $this->finalizedPath();
        $this->checkFile($path);
        if (!file_exists($path)) {
            return [];
        }
        $file = $this->open($path, true);
        try {
            $contents = stream_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException('Cannot read finalized state.');
            }
            $state = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($state)) {
                throw new RuntimeException('Invalid finalized state.');
            }
            $hashes = (array) $state;
            foreach ($hashes as $name => $hash) {
                if (!in_array($name, self::ARTIFACTS, true) || $name === 'checksums.sha256'
                    || !is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
                    throw new RuntimeException('Invalid finalized state.');
                }
            }

            return $hashes;
        } finally {
            fclose($file);
        }
    }

    private function saveManifest(array $hashes): void
    {
        // Never remove or rebaseline authoritative finalization entries.
        $finalized = $this->finalized();
        foreach ($finalized as $name => $hash) {
            if (isset($hashes[$name]) && !hash_equals($hash, $hashes[$name])) {
                throw new RuntimeException('Finalized hash cannot be replaced.');
            }
            $hashes[$name] = $hash;
        }
        ksort($hashes, SORT_STRING);
        // A checksum entry alone does not finalize a provisional summary.
        $state = $hashes;
        if (!isset($finalized['summary.json'])) {
            unset($state['summary.json']);
        }
        $this->atomicWrite(self::FINALIZED, json_encode((object) $state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $contents = '';
        foreach ($hashes as $name => $hash) {
            $contents .= $hash.'  '.$name."\n";
        }
        $this->atomicWrite('checksums.sha256', $contents);
    }
}
