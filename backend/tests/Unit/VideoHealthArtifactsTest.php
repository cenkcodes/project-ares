<?php

namespace App\Services\VideoHealth {
    // Test-only filesystem failure injection, scoped to one temporary destination.
    function rename(string $from, string $to): bool
    {
        $target = $GLOBALS['videoHealthFailedRenameTarget'] ?? null;
        if (is_string($target) && str_replace('\\', '/', $to) === str_replace('\\', '/', $target)) {
            return false;
        }

        return \rename($from, $to);
    }
}

namespace {

use App\Services\VideoHealth\VideoHealthArtifacts;

beforeEach(function () {
    unset($GLOBALS['videoHealthFailedRenameTarget']);
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'video-health-test-'.bin2hex(random_bytes(12));
    $this->artifacts = new VideoHealthArtifacts(42, $this->root);
    $this->directory = $this->artifacts->directory();
});

afterEach(function () {
    unset($GLOBALS['videoHealthFailedRenameTarget']);
    // Delete only this test's random temporary root, never follow directory links.
    if (!str_starts_with($this->root, sys_get_temp_dir().DIRECTORY_SEPARATOR.'video-health-test-')) {
        throw new RuntimeException('Unexpected cleanup root.');
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        if ($item->isLink() || !$item->isDir()) {
            unlink($item->getPathname());
        } else {
            rmdir($item->getPathname());
        }
    }
    rmdir($this->root);
});

it('creates and reopens a private safe run directory', function () {
    expect(is_dir($this->directory))->toBeTrue();
    $reopened = new VideoHealthArtifacts('42', $this->root);
    expect($reopened->directory())->toBe($this->directory);
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(fileperms($this->directory) & 0777)->toBe(0700);
    }
});

it('rejects unsafe run identifiers', function (int|string $id) {
    expect(fn () => new VideoHealthArtifacts($id, $this->root))->toThrow(InvalidArgumentException::class);
})->with([0, -1, '../outside', '1/2', '1\\2', "1\0", 'C:\\outside', '/outside', '01', '1.5', '']);

it('rejects unsafe artifact names', function (string $name) {
    expect(fn () => $this->artifacts->appendJsonl($name, ['id' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->artifacts->sha256($name))->toThrow(InvalidArgumentException::class);
})->with(['unknown.jsonl', '../results.jsonl', 'nested/results.jsonl', 'nested\\results.jsonl', '/results.jsonl', "results.jsonl\0"]);

it('streams exactly one UTF-8 object per JSONL line', function () {
    foreach ([['id' => 1, 'url' => 'https://example.com/猫', 'note' => "line\nbreak"], ['id' => 2], []] as $row) {
        $this->artifacts->appendJsonl('results.jsonl', $row);
    }
    $lines = file($this->directory.'/results.jsonl');
    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toContain('https://example.com/猫')
        ->and(json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR)['note'])->toBe("line\nbreak")
        ->and($lines[2])->toBe("{}\n");
});

it('rejects invalid JSON encoding before writing', function () {
    expect(fn () => $this->artifacts->appendJsonl('results.jsonl', ['bad' => "\xB1\x31"]))->toThrow(JsonException::class);
    expect(file_exists($this->directory.'/results.jsonl'))->toBeFalse();
});

it('rejects JSONL lists rather than writing array rows', function () {
    expect(fn () => $this->artifacts->appendJsonl('catalog.jsonl', [1, 2]))->toThrow(InvalidArgumentException::class);
});

it('surfaces write destination failures', function () {
    mkdir($this->directory.'/results.jsonl');
    expect(fn () => $this->artifacts->appendJsonl('results.jsonl', ['id' => 1]))->toThrow(RuntimeException::class);
});

it('atomically writes and round trips a final summary without leftover temporary files', function () {
    $summary = ['counts' => ['pass' => 5], 'complete' => true];
    $this->artifacts->writeSummary($summary);
    expect(json_decode(file_get_contents($this->directory.'/summary.json'), true, 512, JSON_THROW_ON_ERROR))->toBe($summary)
        ->and(glob($this->directory.'/*.tmp'))->toBe([])
        ->and(glob($this->directory.'/.*.tmp'))->toBe([])
        ->and($this->artifacts->verifyChecksumManifest())->toBeTrue();
    expect(fn () => $this->artifacts->writeSummary(['replacement' => true]))->toThrow(RuntimeException::class);
});

it('surfaces atomic summary destination failure without replacing the directory', function () {
    mkdir($this->directory.'/summary.json');
    expect(fn () => $this->artifacts->writeSummary(['id' => 1]))->toThrow(RuntimeException::class);
    expect(is_dir($this->directory.'/summary.json'))->toBeTrue();
});

it('computes and verifies SHA256 and rejects mismatches', function () {
    $this->artifacts->appendJsonl('catalog.jsonl', ['id' => 1]);
    $expected = hash('sha256', "{\"id\":1}\n");
    expect($this->artifacts->sha256('catalog.jsonl'))->toBe($expected)
        ->and($this->artifacts->verify('catalog.jsonl', $expected))->toBeTrue();
    expect(fn () => $this->artifacts->verify('catalog.jsonl', str_repeat('0', 64)))->toThrow(RuntimeException::class);
    expect(fn () => $this->artifacts->verify('catalog.jsonl', 'invalid'))->toThrow(RuntimeException::class);
});

it('creates a deterministic explicit checksum allowlist excluding itself', function () {
    $this->artifacts->appendJsonl('results.jsonl', ['id' => 2]);
    $this->artifacts->appendJsonl('catalog.jsonl', ['id' => 1]);
    $this->artifacts->appendJsonl('reconfirmation.jsonl', ['id' => 3]);
    $this->artifacts->generateChecksumManifest(['results.jsonl', 'catalog.jsonl']);
    $expected = $this->artifacts->sha256('catalog.jsonl')."  catalog.jsonl\n"
        .$this->artifacts->sha256('results.jsonl')."  results.jsonl\n";
    expect(file_get_contents($this->directory.'/checksums.sha256'))->toBe($expected)
        ->and($this->artifacts->verifyChecksumManifest())->toBeTrue();
    $this->artifacts->generateChecksumManifest(['catalog.jsonl', 'results.jsonl']);
    expect(file_get_contents($this->directory.'/checksums.sha256'))->toBe($expected);
    expect(fn () => $this->artifacts->generateChecksumManifest(['checksums.sha256']))->toThrow(InvalidArgumentException::class);
});

it('detects artifact tampering and refuses to rebaseline a finalized hash', function () {
    $this->artifacts->appendJsonl('results.jsonl', ['id' => 1]);
    $this->artifacts->generateChecksumManifest(['results.jsonl']);
    file_put_contents($this->directory.'/results.jsonl', "{\"id\":2}\n");
    expect(fn () => $this->artifacts->verifyChecksumManifest())->toThrow(RuntimeException::class);
    expect(fn () => $this->artifacts->generateChecksumManifest(['results.jsonl']))->toThrow(RuntimeException::class);
});

it('rejects malformed or self-referential checksum manifests', function (string $contents) {
    file_put_contents($this->directory.'/checksums.sha256', $contents);
    expect(fn () => $this->artifacts->verifyChecksumManifest())->toThrow(RuntimeException::class);
})->with(['garbage', str_repeat('a', 64)."  checksums.sha256\n", str_repeat('a', 64)."  ../outside\n"]);

it('streams exact backup rows and persists finalization across reopen', function () {
    $rows = [['id' => 1, 'is_active' => true, 'original' => null], ['id' => 2, 'title' => 'Exact title']];
    $this->artifacts->startBackup();
    foreach ($rows as $row) {
        $this->artifacts->appendBackup($row);
    }
    expect(array_map(fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($this->directory.'/db-backup.jsonl')))->toBe($rows);
    $final = $this->artifacts->finalizeBackup();
    expect($final['row_count'])->toBe(2)->and($final['sha256'])->toBe(hash_file('sha256', $this->directory.'/db-backup.jsonl'));
    $reopened = new VideoHealthArtifacts(42, $this->root);
    expect($reopened->finalizeBackup())->toBe($final);
    expect(fn () => $reopened->startBackup(true))->toThrow(RuntimeException::class);
    expect(fn () => $reopened->appendBackup(['id' => 3]))->toThrow(RuntimeException::class);
});

it('requires explicit reset for an unfinished backup and permits recovery appends', function () {
    expect(fn () => $this->artifacts->appendBackup(['id' => 1]))->toThrow(RuntimeException::class);
    $this->artifacts->startBackup();
    $this->artifacts->appendBackup(['id' => 1]);
    expect(fn () => $this->artifacts->startBackup())->toThrow(RuntimeException::class);
    $this->artifacts->startBackup(true);
    $reopened = new VideoHealthArtifacts(42, $this->root);
    $reopened->appendBackup(['id' => 2]);
    expect($reopened->finalizeBackup()['row_count'])->toBe(1);
});

it('prevents append after finalizing any streamed artifact', function (string $name) {
    $this->artifacts->appendJsonl($name, ['id' => 1]);
    expect($this->artifacts->finalizeJsonl($name)['row_count'])->toBe(1);
    expect(fn () => $this->artifacts->appendJsonl($name, ['id' => 2]))->toThrow(RuntimeException::class);
})->with(['catalog.jsonl', 'results.jsonl', 'reconfirmation.jsonl']);

it('rejects incomplete or non-object backup rows on finalization', function (string $contents) {
    file_put_contents($this->directory.'/db-backup.jsonl', $contents);
    expect(fn () => $this->artifacts->finalizeBackup())->toThrow(RuntimeException::class);
})->with(['{"id":1}', "[]\n"]);

it('rejects unexpected run directory objects', function () {
    file_put_contents($this->root.'/43', 'not a directory');
    expect(fn () => new VideoHealthArtifacts(43, $this->root))->toThrow(RuntimeException::class);
});

it('rejects symlinked run directories where creation is supported', function () {
    if (!@symlink($this->directory, $this->root.'/43')) {
        $this->markTestSkipped('Windows/platform symlink creation privileges are unavailable.');
    }
    expect(fn () => new VideoHealthArtifacts(43, $this->root))->toThrow(RuntimeException::class);
});

it('rejects symlinked artifact files where creation is supported', function () {
    file_put_contents($this->root.'/target', 'untouched');
    if (!@symlink($this->root.'/target', $this->directory.'/results.jsonl')) {
        $this->markTestSkipped('Windows/platform symlink creation privileges are unavailable.');
    }
    expect(fn () => $this->artifacts->appendJsonl('results.jsonl', ['id' => 1]))->toThrow(RuntimeException::class);
    expect(file_get_contents($this->root.'/target'))->toBe('untouched');
});

it('has no database, HTTP or public disk dependency', function () {
    $source = file_get_contents(__DIR__.'/../../app/Services/VideoHealth/VideoHealthArtifacts.php');
    expect($source)->not->toContain('Storage::', 'DB::', 'Http::', 'Guzzle', 'VideoHealthRun', 'EmbedHealthClassifier')
        ->and($source)->toContain("storage_path('app/private/video-health/runs')");
});

it('rejects empty and missing checksum manifests', function (bool $createEmpty) {
    if ($createEmpty) {
        file_put_contents($this->directory.'/checksums.sha256', '');
    }
    expect(fn () => $this->artifacts->verifyChecksumManifest())->toThrow(RuntimeException::class);
})->with([true, false]);

it('records backup finalization durably and verifies it after reopening', function () {
    $this->artifacts->startBackup();
    $this->artifacts->appendBackup(['id' => 10, 'is_active' => true]);
    $final = $this->artifacts->finalizeBackup();
    $state = json_decode(file_get_contents($this->directory.'/.finalized.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($final['row_count'])->toBe(1)
        ->and($state)->toBe(['db-backup.jsonl' => $final['sha256']])
        ->and(glob($this->directory.'/.*.tmp'))->toBe([]);
    $reopened = new VideoHealthArtifacts(42, $this->root);
    expect($reopened->verifyChecksumManifest())->toBeTrue();
    expect(fn () => $reopened->appendBackup(['id' => 11]))->toThrow(RuntimeException::class);
    expect(fn () => $reopened->startBackup(true))->toThrow(RuntimeException::class);
    expect(fn () => $reopened->sha256('.finalized.json'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $reopened->generateChecksumManifest(['.finalized.json']))->toThrow(InvalidArgumentException::class);
});

it('keeps reopened backups immutable despite missing or corrupt checksum manifests', function (?string $contents) {
    $this->artifacts->startBackup();
    $this->artifacts->appendBackup(['id' => 10]);
    $final = $this->artifacts->finalizeBackup();
    $marker = file_get_contents($this->directory.'/.finalized.json');
    $backup = file_get_contents($this->directory.'/db-backup.jsonl');
    if ($contents === null) {
        unlink($this->directory.'/checksums.sha256');
    } else {
        file_put_contents($this->directory.'/checksums.sha256', $contents);
    }
    $reopened = new VideoHealthArtifacts(42, $this->root);
    expect(fn () => $reopened->appendBackup(['id' => 11]))->toThrow(RuntimeException::class);
    expect(fn () => $reopened->startBackup(true))->toThrow(RuntimeException::class);
    expect(fn () => $reopened->startBackup())->toThrow(RuntimeException::class);
    expect(fn () => $reopened->verifyChecksumManifest())->toThrow(RuntimeException::class);
    expect(file_get_contents($this->directory.'/.finalized.json'))->toBe($marker)
        ->and(file_get_contents($this->directory.'/db-backup.jsonl'))->toBe($backup)
        ->and($reopened->sha256('db-backup.jsonl'))->toBe($final['sha256']);
})->with([null, '', 'truncated hash', str_repeat('a', 64).'  db-backup.jsonl']);

it('rejects manifests omitting a finalized backup', function () {
    $this->artifacts->startBackup();
    $this->artifacts->appendBackup(['id' => 10]);
    $this->artifacts->finalizeBackup();
    $this->artifacts->appendJsonl('catalog.jsonl', ['id' => 10]);
    file_put_contents($this->directory.'/checksums.sha256', $this->artifacts->sha256('catalog.jsonl')."  catalog.jsonl\n");
    expect(fn () => (new VideoHealthArtifacts(42, $this->root))->verifyChecksumManifest())->toThrow(RuntimeException::class);
});

it('rejects manifest hashes that disagree with durable finalization metadata', function () {
    $this->artifacts->startBackup();
    $this->artifacts->appendBackup(['id' => 10]);
    $this->artifacts->finalizeBackup();
    file_put_contents($this->directory.'/checksums.sha256', str_repeat('0', 64)."  db-backup.jsonl\n");
    expect(fn () => $this->artifacts->verifyChecksumManifest())->toThrow(RuntimeException::class);
});

it('rejects tampered or missing finalized backup contents', function (bool $remove) {
    $this->artifacts->startBackup();
    $this->artifacts->appendBackup(['id' => 10]);
    $this->artifacts->finalizeBackup();
    if ($remove) {
        unlink($this->directory.'/db-backup.jsonl');
    } else {
        file_put_contents($this->directory.'/db-backup.jsonl', "{\"id\":11}\n");
    }
    expect(fn () => $this->artifacts->verifyChecksumManifest())->toThrow(RuntimeException::class);
})->with([false, true]);

it('preserves finalized entries when regenerating a lost manifest', function () {
    $this->artifacts->startBackup();
    $this->artifacts->appendBackup(['id' => 10]);
    $final = $this->artifacts->finalizeBackup();
    unlink($this->directory.'/checksums.sha256');
    $this->artifacts->appendJsonl('catalog.jsonl', ['id' => 10]);
    $this->artifacts->generateChecksumManifest(['catalog.jsonl']);
    $state = json_decode(file_get_contents($this->directory.'/.finalized.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($state['db-backup.jsonl'])->toBe($final['sha256'])
        ->and($this->artifacts->verifyChecksumManifest())->toBeTrue()
        ->and(file_get_contents($this->directory.'/checksums.sha256'))->not->toContain('.finalized.json');
});

it('cannot rebaseline a finalized backup after deleting its checksum manifest', function () {
    $this->artifacts->startBackup();
    $this->artifacts->appendBackup(['id' => 10]);
    $this->artifacts->finalizeBackup();
    $marker = file_get_contents($this->directory.'/.finalized.json');
    unlink($this->directory.'/checksums.sha256');
    file_put_contents($this->directory.'/db-backup.jsonl', "{\"id\":11}\n");
    expect(fn () => $this->artifacts->generateChecksumManifest(['db-backup.jsonl']))->toThrow(RuntimeException::class);
    expect(fn () => $this->artifacts->finalizeBackup())->toThrow(RuntimeException::class);
    expect(file_get_contents($this->directory.'/.finalized.json'))->toBe($marker);
});

it('rejects a symlinked finalization control file where creation is supported', function () {
    file_put_contents($this->root.'/state-target', '{}');
    if (!@symlink($this->root.'/state-target', $this->directory.'/.finalized.json')) {
        $this->markTestSkipped('Windows/platform symlink creation privileges are unavailable.');
    }
    expect(fn () => $this->artifacts->startBackup())->toThrow(RuntimeException::class);
    expect(file_get_contents($this->root.'/state-target'))->toBe('{}');
});

it('atomically writes an unfinalized provisional summary and permits replacement after reopening', function () {
    $this->artifacts->writeProvisionalSummary(['status' => 'completed']);
    expect(json_decode(file_get_contents($this->directory.'/summary.json'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['status' => 'completed'])
        ->and(file_exists($this->directory.'/.finalized.json'))->toBeFalse()
        ->and(glob($this->directory.'/.*.tmp'))->toBe([]);
    (new VideoHealthArtifacts(42, $this->root))->writeProvisionalSummary(['status' => 'failed']);
    expect(json_decode(file_get_contents($this->directory.'/summary.json'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['status' => 'failed']);
});

it('keeps a correct provisional checksum entry mutable until explicit summary finalization', function () {
    $this->artifacts->appendJsonl('results.jsonl', ['video_id' => 10]);
    $this->artifacts->finalizeJsonl('results.jsonl');
    $this->artifacts->writeProvisionalSummary(['status' => 'completed']);
    $this->artifacts->generateChecksumManifest(['results.jsonl', 'summary.json']);
    $state = json_decode(file_get_contents($this->directory.'/.finalized.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($state)->not->toHaveKey('summary.json')
        ->and($state)->toHaveKey('results.jsonl')
        ->and($this->artifacts->verifyChecksumManifest())->toBeTrue();
    (new VideoHealthArtifacts(42, $this->root))->writeProvisionalSummary(['status' => 'failed']);
    expect(fn () => $this->artifacts->verifyChecksumManifest())->toThrow(RuntimeException::class);
    $this->artifacts->generateChecksumManifest(['summary.json']);
    expect($this->artifacts->verifyChecksumManifest())->toBeTrue();
});

it('durably finalizes a verified summary without changing its contents or checksum manifest', function () {
    $this->artifacts->writeProvisionalSummary(['status' => 'completed']);
    $this->artifacts->generateChecksumManifest(['summary.json']);
    expect($this->artifacts->verifyChecksumManifest())->toBeTrue();
    $contents = file_get_contents($this->directory.'/summary.json');
    $manifest = file_get_contents($this->directory.'/checksums.sha256');
    $hash = $this->artifacts->finalizeSummary();
    $state = json_decode(file_get_contents($this->directory.'/.finalized.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($hash)->toBe(hash('sha256', $contents))
        ->and($state['summary.json'])->toBe($hash)
        ->and(file_get_contents($this->directory.'/summary.json'))->toBe($contents)
        ->and(file_get_contents($this->directory.'/checksums.sha256'))->toBe($manifest);
    $reopened = new VideoHealthArtifacts(42, $this->root);
    expect($reopened->finalizeSummary())->toBe($hash);
    expect(fn () => $reopened->writeProvisionalSummary(['status' => 'failed']))->toThrow(RuntimeException::class);
    expect(fn () => $reopened->writeSummary(['status' => 'failed']))->toThrow(RuntimeException::class);
});

it('rejects summary finalization without an existing verified summary entry', function (string $state) {
    if ($state !== 'missing') {
        $this->artifacts->writeProvisionalSummary(['status' => 'completed']);
    }
    if ($state === 'omitted') {
        $this->artifacts->appendJsonl('catalog.jsonl', ['video_id' => 1]);
        $this->artifacts->generateChecksumManifest(['catalog.jsonl']);
    }
    expect(fn () => $this->artifacts->finalizeSummary())->toThrow(RuntimeException::class);
})->with(['missing', 'no-manifest', 'omitted']);

it('preserves evidence and permits a failed summary after checksum atomic publication fails', function () {
    $this->artifacts->appendJsonl('catalog.jsonl', ['video_id' => 10]);
    $this->artifacts->appendJsonl('results.jsonl', ['video_id' => 10, 'classification' => 'PASS']);
    $this->artifacts->finalizeJsonl('catalog.jsonl');
    $this->artifacts->finalizeJsonl('results.jsonl');
    $catalog = file_get_contents($this->directory.'/catalog.jsonl');
    $results = file_get_contents($this->directory.'/results.jsonl');
    $this->artifacts->writeProvisionalSummary(['status' => 'completed']);
    $GLOBALS['videoHealthFailedRenameTarget'] = $this->directory.'/checksums.sha256';
    expect(fn () => $this->artifacts->generateChecksumManifest(['catalog.jsonl', 'results.jsonl', 'summary.json']))
        ->toThrow(RuntimeException::class, 'Atomic artifact replacement failed.');
    unset($GLOBALS['videoHealthFailedRenameTarget']);
    $state = json_decode(file_get_contents($this->directory.'/.finalized.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($state)->not->toHaveKey('summary.json')
        ->and(json_decode(file_get_contents($this->directory.'/summary.json'), true, 512, JSON_THROW_ON_ERROR)['status'])->toBe('completed');
    $reopened = new VideoHealthArtifacts(42, $this->root);
    $reopened->writeProvisionalSummary(['status' => 'failed']);
    expect(json_decode(file_get_contents($this->directory.'/summary.json'), true, 512, JSON_THROW_ON_ERROR)['status'])->toBe('failed')
        ->and(file_get_contents($this->directory.'/catalog.jsonl'))->toBe($catalog)
        ->and(file_get_contents($this->directory.'/results.jsonl'))->toBe($results)
        ->and(glob($this->directory.'/.*.tmp'))->toBe([]);
    $reopened->generateChecksumManifest(['summary.json']);
    expect($reopened->verifyChecksumManifest())->toBeTrue();
    $reopened->finalizeSummary();
    expect(fn () => $reopened->writeProvisionalSummary(['status' => 'completed']))->toThrow(RuntimeException::class);
});

it('leaves legacy writeSummary provisional when checksum publication fails', function () {
    $GLOBALS['videoHealthFailedRenameTarget'] = $this->directory.'/checksums.sha256';
    expect(fn () => $this->artifacts->writeSummary(['status' => 'completed']))->toThrow(RuntimeException::class);
    unset($GLOBALS['videoHealthFailedRenameTarget']);
    $state = json_decode(file_get_contents($this->directory.'/.finalized.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($state)->not->toHaveKey('summary.json');
    $this->artifacts->writeProvisionalSummary(['status' => 'failed']);
    expect(json_decode(file_get_contents($this->directory.'/summary.json'), true, 512, JSON_THROW_ON_ERROR)['status'])->toBe('failed');
});

it('permits provisional replacement after checksum verification fails', function () {
    $this->artifacts->writeProvisionalSummary(['status' => 'completed']);
    $this->artifacts->generateChecksumManifest(['summary.json']);
    file_put_contents($this->directory.'/checksums.sha256', str_repeat('0', 64)."  summary.json\n");
    expect(fn () => $this->artifacts->verifyChecksumManifest())->toThrow(RuntimeException::class);
    expect(fn () => $this->artifacts->finalizeSummary())->toThrow(RuntimeException::class);
    $this->artifacts->writeProvisionalSummary(['status' => 'failed']);
    expect(json_decode(file_get_contents($this->directory.'/summary.json'), true, 512, JSON_THROW_ON_ERROR)['status'])->toBe('failed');
});

it('keeps a finalized summary immutable when its manifest is missing or corrupted', function (?string $manifest) {
    $this->artifacts->writeSummary(['status' => 'completed']);
    if ($manifest === null) {
        unlink($this->directory.'/checksums.sha256');
    } else {
        file_put_contents($this->directory.'/checksums.sha256', $manifest);
    }
    $reopened = new VideoHealthArtifacts(42, $this->root);
    expect(fn () => $reopened->writeProvisionalSummary(['status' => 'failed']))->toThrow(RuntimeException::class);
    expect(fn () => $reopened->verifyChecksumManifest())->toThrow(RuntimeException::class);
})->with([null, '', 'malformed']);

it('rejects finalized summary manifest disagreement and file tampering', function (bool $tamperFile) {
    $this->artifacts->writeSummary(['status' => 'completed']);
    if ($tamperFile) {
        file_put_contents($this->directory.'/summary.json', '{"status":"failed"}');
    } else {
        file_put_contents($this->directory.'/checksums.sha256', str_repeat('0', 64)."  summary.json\n");
    }
    expect(fn () => $this->artifacts->verifyChecksumManifest())->toThrow(RuntimeException::class);
    expect(fn () => $this->artifacts->finalizeSummary())->toThrow(RuntimeException::class);
    expect(fn () => $this->artifacts->generateChecksumManifest(['summary.json']))->toThrow(RuntimeException::class);
})->with([false, true]);

it('preserves the previous provisional summary if atomic replacement fails', function () {
    $this->artifacts->writeProvisionalSummary(['status' => 'completed']);
    $contents = file_get_contents($this->directory.'/summary.json');
    $GLOBALS['videoHealthFailedRenameTarget'] = $this->directory.'/summary.json';
    expect(fn () => $this->artifacts->writeProvisionalSummary(['status' => 'failed']))->toThrow(RuntimeException::class);
    expect(file_get_contents($this->directory.'/summary.json'))->toBe($contents)
        ->and(glob($this->directory.'/.*.tmp'))->toBe([]);
});

it('leaves summary provisional if durable finalization publication fails', function () {
    $this->artifacts->writeProvisionalSummary(['status' => 'completed']);
    $this->artifacts->generateChecksumManifest(['summary.json']);
    $GLOBALS['videoHealthFailedRenameTarget'] = $this->directory.'/.finalized.json';
    expect(fn () => $this->artifacts->finalizeSummary())->toThrow(RuntimeException::class);
    unset($GLOBALS['videoHealthFailedRenameTarget']);
    $state = json_decode(file_get_contents($this->directory.'/.finalized.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($state)->not->toHaveKey('summary.json');
    $this->artifacts->writeProvisionalSummary(['status' => 'failed']);
    expect(json_decode(file_get_contents($this->directory.'/summary.json'), true, 512, JSON_THROW_ON_ERROR)['status'])->toBe('failed');
});

it('rejects unsafe provisional summary files and invalid JSON without replacing existing contents', function () {
    $this->artifacts->writeProvisionalSummary(['status' => 'completed']);
    $contents = file_get_contents($this->directory.'/summary.json');
    expect(fn () => $this->artifacts->writeProvisionalSummary(['invalid' => "\xB1\x31"]))->toThrow(JsonException::class);
    expect(file_get_contents($this->directory.'/summary.json'))->toBe($contents);
    unlink($this->directory.'/summary.json');
    mkdir($this->directory.'/summary.json');
    expect(fn () => $this->artifacts->writeProvisionalSummary(['status' => 'failed']))->toThrow(RuntimeException::class);
    expect(fn () => $this->artifacts->finalizeSummary())->toThrow(RuntimeException::class);
});

it('reads allowed evidence incrementally in original order as associative rows', function (string $name) {
    $rows = [['video_id' => 8, 'nested' => ['note' => '猫']], ['video_id' => 2], []];
    foreach ($rows as $row) {
        $this->artifacts->appendJsonl($name, $row);
    }
    $reader = $this->artifacts->streamJsonl($name);
    expect($reader)->toBeInstanceOf(Generator::class);
    $actual = [];
    foreach ($reader as $row) {
        expect($row)->toBeArray();
        $actual[] = $row;
    }
    expect($actual)->toBe($rows);
})->with(['results.jsonl', 'reconfirmation.jsonl', 'catalog.jsonl']);

it('reads an empty evidence file without creating control files', function () {
    file_put_contents($this->directory.'/results.jsonl', '');
    $before = scandir($this->directory);
    expect(iterator_to_array($this->artifacts->streamJsonl('results.jsonl')))->toBe([])
        ->and(scandir($this->directory))->toBe($before);
});

it('refuses missing evidence without creating it or a lock file', function () {
    $before = scandir($this->directory);
    expect(fn () => iterator_to_array($this->artifacts->streamJsonl('results.jsonl')))->toThrow(RuntimeException::class);
    expect(scandir($this->directory))->toBe($before);
});

it('does not recreate a missing run directory while reading', function () {
    rmdir($this->directory);
    expect(fn () => iterator_to_array($this->artifacts->streamJsonl('results.jsonl')))->toThrow(RuntimeException::class);
    expect(is_dir($this->directory))->toBeFalse();
});

it('rejects every non-evidence reader name', function (string $name) {
    expect(fn () => iterator_to_array($this->artifacts->streamJsonl($name)))->toThrow(InvalidArgumentException::class);
})->with(['unknown.jsonl', 'db-backup.jsonl', 'summary.json', 'checksums.sha256', '.finalized.json', '.lock',
    '.results.jsonl.tmp', '../results.jsonl', 'nested/results.jsonl', 'nested\\results.jsonl', '/results.jsonl', "results.jsonl\0"]);

it('rejects malformed non-object blank or incomplete records with safe line context', function (string $contents) {
    file_put_contents($this->directory.'/results.jsonl', "{\"video_id\":1}\n".$contents);
    try {
        iterator_to_array($this->artifacts->streamJsonl('results.jsonl'));
        $this->fail('Invalid record was accepted.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('results.jsonl', 'line 2')->not->toContain('PRIVATE_PAYLOAD');
    }
})->with(["{PRIVATE_PAYLOAD\n", "\n", "  \n", "null\n", "true\n", "123\n", "\"PRIVATE_PAYLOAD\"\n", "[]\n", "[1,2]\n", '{"id":2}', "{\"bad\":\"\xB1\"}\n"]);

it('rejects oversize records even when their prefix is valid JSON', function () {
    file_put_contents($this->directory.'/results.jsonl', '{}'.str_repeat(' ', 1048576)."\n");
    expect(fn () => iterator_to_array($this->artifacts->streamJsonl('results.jsonl')))
        ->toThrow(RuntimeException::class, 'JSONL record exceeds size limit: results.jsonl at line 1.');
});

it('accepts an object at the exact record size limit including newline', function () {
    $value = str_repeat('x', 1048576 - strlen('{"value":""}') - 1);
    $line = '{"value":"'.$value.'"}'."\n";
    file_put_contents($this->directory.'/results.jsonl', $line);
    expect(strlen($line))->toBe(1048576)
        ->and(iterator_to_array($this->artifacts->streamJsonl('results.jsonl')))->toBe([['value' => $value]]);
});

it('rejects a non-regular readable artifact', function () {
    mkdir($this->directory.'/results.jsonl');
    expect(fn () => iterator_to_array($this->artifacts->streamJsonl('results.jsonl')))->toThrow(RuntimeException::class);
});

it('rejects a symlinked readable artifact where creation is supported', function () {
    file_put_contents($this->root.'/read-target', "{\"id\":1}\n");
    if (!@symlink($this->root.'/read-target', $this->directory.'/results.jsonl')) {
        $this->markTestSkipped('Windows/platform symlink creation privileges are unavailable.');
    }
    expect(fn () => iterator_to_array($this->artifacts->streamJsonl('results.jsonl')))->toThrow(RuntimeException::class);
    expect(file_get_contents($this->root.'/read-target'))->toBe("{\"id\":1}\n");
});

it('reads provisional and finalized evidence without changing contents or metadata', function (bool $finalize) {
    $this->artifacts->appendJsonl('results.jsonl', ['id' => 1]);
    if ($finalize) {
        $this->artifacts->finalizeJsonl('results.jsonl');
    }
    $before = [];
    foreach (scandir($this->directory) as $name) {
        if (is_file($this->directory.'/'.$name)) {
            $before[$name] = [hash_file('sha256', $this->directory.'/'.$name), filemtime($this->directory.'/'.$name)];
        }
    }
    expect(iterator_to_array($this->artifacts->streamJsonl('results.jsonl')))->toBe([['id' => 1]]);
    $after = [];
    foreach (scandir($this->directory) as $name) {
        if (is_file($this->directory.'/'.$name)) {
            $after[$name] = [hash_file('sha256', $this->directory.'/'.$name), filemtime($this->directory.'/'.$name)];
        }
    }
    expect($after)->toBe($before);
})->with([false, true]);

it('holds a shared lock while suspended and releases it when abandoned', function () {
    $this->artifacts->appendJsonl('results.jsonl', ['id' => 1]);
    $reader = $this->artifacts->streamJsonl('results.jsonl');
    $reader->rewind();
    $other = fopen($this->directory.'/results.jsonl', 'rb');
    try {
        expect(flock($other, LOCK_SH | LOCK_NB))->toBeTrue();
        flock($other, LOCK_UN);
        expect(flock($other, LOCK_EX | LOCK_NB))->toBeFalse();
        unset($reader);
        expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();
    } finally {
        flock($other, LOCK_UN);
        fclose($other);
    }
});

it('releases reader resources after a parse failure', function () {
    file_put_contents($this->directory.'/results.jsonl', "bad\n");
    $reader = $this->artifacts->streamJsonl('results.jsonl');
    expect(fn () => $reader->rewind())->toThrow(RuntimeException::class);
    $other = fopen($this->directory.'/results.jsonl', 'rb');
    try {
        expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();
    } finally {
        flock($other, LOCK_UN);
        fclose($other);
    }
});

it('cleans up an inline reader when the caller breaks or throws', function (bool $throw) {
    $this->artifacts->appendJsonl('results.jsonl', ['id' => 1]);
    try {
        foreach ($this->artifacts->streamJsonl('results.jsonl') as $row) {
            if ($throw) {
                throw new LogicException('Caller stopped.');
            }
            break;
        }
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('Caller stopped.');
    }
    $other = fopen($this->directory.'/results.jsonl', 'rb');
    try {
        expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();
    } finally {
        flock($other, LOCK_UN);
        fclose($other);
    }
})->with([false, true]);

it('initializes zero-byte evidence that streams and finalizes normally across reopen', function (string $name) {
    $this->artifacts->initializeEmptyJsonl($name);
    expect(filesize($this->directory.'/'.$name))->toBe(0)
        ->and(file_get_contents($this->directory.'/'.$name))->toBe('')
        ->and(iterator_to_array($this->artifacts->streamJsonl($name)))->toBe([])
        ->and(file_exists($this->directory.'/.finalized.json'))->toBeFalse()
        ->and(file_exists($this->directory.'/checksums.sha256'))->toBeFalse();
    $final = $this->artifacts->finalizeJsonl($name);
    expect($final)->toBe(['row_count' => 0, 'sha256' => hash('sha256', '')]);
    $this->artifacts->generateChecksumManifest([$name]);
    expect($this->artifacts->verifyChecksumManifest())->toBeTrue()
        ->and($this->artifacts->verify($name, $final['sha256']))->toBeTrue();
    $reopened = new VideoHealthArtifacts(42, $this->root);
    expect($reopened->finalizeJsonl($name))->toBe($final)
        ->and(iterator_to_array($reopened->streamJsonl($name)))->toBe([])
        ->and(json_decode(file_get_contents($this->directory.'/.finalized.json'), true, 512, JSON_THROW_ON_ERROR)[$name])
        ->toBe($final['sha256']);
    expect(fn () => $reopened->appendJsonl($name, ['id' => 1]))->toThrow(RuntimeException::class);
    expect(fn () => $reopened->initializeEmptyJsonl($name))->toThrow(RuntimeException::class);
    expect(filesize($this->directory.'/'.$name))->toBe(0);
})->with(['catalog.jsonl', 'results.jsonl', 'reconfirmation.jsonl']);

it('refuses initialization of existing evidence without resetting its bytes', function (bool $empty) {
    if ($empty) {
        $this->artifacts->initializeEmptyJsonl('reconfirmation.jsonl');
    } else {
        $this->artifacts->appendJsonl('reconfirmation.jsonl', ['id' => 42]);
    }
    $contents = file_get_contents($this->directory.'/reconfirmation.jsonl');
    $reopened = new VideoHealthArtifacts(42, $this->root);
    expect(fn () => $reopened->initializeEmptyJsonl('reconfirmation.jsonl'))->toThrow(RuntimeException::class);
    expect(file_get_contents($this->directory.'/reconfirmation.jsonl'))->toBe($contents);
})->with([true, false]);

it('keeps initialized evidence appendable until finalization', function () {
    $this->artifacts->initializeEmptyJsonl('reconfirmation.jsonl');
    $this->artifacts->appendJsonl('reconfirmation.jsonl', ['id' => 42]);
    expect(iterator_to_array($this->artifacts->streamJsonl('reconfirmation.jsonl')))->toBe([['id' => 42]])
        ->and($this->artifacts->finalizeJsonl('reconfirmation.jsonl')['row_count'])->toBe(1);
});

it('rejects non-evidence initialization targets before creating any state', function (string $name) {
    $before = scandir($this->directory);
    expect(fn () => $this->artifacts->initializeEmptyJsonl($name))->toThrow(InvalidArgumentException::class);
    expect(scandir($this->directory))->toBe($before);
})->with(['unknown.jsonl', 'db-backup.jsonl', 'summary.json', 'checksums.sha256', '.finalized.json', '.lock',
    '.reconfirmation.jsonl.tmp', '../reconfirmation.jsonl', 'nested/reconfirmation.jsonl', 'nested\\reconfirmation.jsonl',
    '/reconfirmation.jsonl', "reconfirmation.jsonl\0"]);

it('refuses to recreate missing finalized empty evidence even after the manifest is lost', function () {
    $this->artifacts->initializeEmptyJsonl('reconfirmation.jsonl');
    $this->artifacts->finalizeJsonl('reconfirmation.jsonl');
    $state = file_get_contents($this->directory.'/.finalized.json');
    unlink($this->directory.'/reconfirmation.jsonl');
    unlink($this->directory.'/checksums.sha256');
    expect(fn () => (new VideoHealthArtifacts(42, $this->root))->initializeEmptyJsonl('reconfirmation.jsonl'))
        ->toThrow(RuntimeException::class);
    expect(file_exists($this->directory.'/reconfirmation.jsonl'))->toBeFalse()
        ->and(file_get_contents($this->directory.'/.finalized.json'))->toBe($state);
});

it('rejects unsafe empty evidence destinations and surfaces creation failure', function () {
    mkdir($this->directory.'/reconfirmation.jsonl');
    expect(fn () => $this->artifacts->initializeEmptyJsonl('reconfirmation.jsonl'))->toThrow(RuntimeException::class);
    expect(is_dir($this->directory.'/reconfirmation.jsonl'))->toBeTrue();
});

it('rejects a symlinked empty initialization target where creation is supported', function () {
    file_put_contents($this->root.'/empty-target', 'untouched');
    if (!@symlink($this->root.'/empty-target', $this->directory.'/reconfirmation.jsonl')) {
        $this->markTestSkipped('Windows/platform symlink creation privileges are unavailable.');
    }
    expect(fn () => $this->artifacts->initializeEmptyJsonl('reconfirmation.jsonl'))->toThrow(RuntimeException::class);
    expect(file_get_contents($this->root.'/empty-target'))->toBe('untouched');
});

it('still rejects an empty checksum manifest after finalizing empty evidence', function () {
    $this->artifacts->initializeEmptyJsonl('reconfirmation.jsonl');
    $this->artifacts->finalizeJsonl('reconfirmation.jsonl');
    file_put_contents($this->directory.'/checksums.sha256', '');
    expect(fn () => $this->artifacts->verifyChecksumManifest())->toThrow(RuntimeException::class);
    expect(filesize($this->directory.'/reconfirmation.jsonl'))->toBe(0);
});

it('streams many records with bounded memory and does not parse future rows eagerly', function () {
    $file = fopen($this->directory.'/results.jsonl', 'wb');
    for ($id = 1; $id <= 10000; $id++) {
        fwrite($file, json_encode(['id' => $id, 'note' => str_repeat('x', 1024)], JSON_THROW_ON_ERROR)."\n");
    }
    fwrite($file, "INVALID_FUTURE_ROW\n");
    fclose($file);
    $start = memory_get_usage();
    $maximum = $start;
    $count = 0;
    $reader = $this->artifacts->streamJsonl('results.jsonl');
    try {
        foreach ($reader as $row) {
            $count++;
            if ($row['id'] !== $count) {
                $this->fail('Record order changed.');
            }
            $maximum = max($maximum, memory_get_usage());
        }
        $this->fail('Malformed final record was accepted.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('line 10001');
    }
    expect($count)->toBe(10000)->and($maximum - $start)->toBeLessThan(4 * 1048576);
});

}
