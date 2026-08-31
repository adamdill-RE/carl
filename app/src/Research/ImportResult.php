<?php

declare(strict_types=1);

namespace Carl\Research;

/**
 * What a validation pass found. Nothing is written until an admin confirms a
 * result that has no errors (handoff Section 9.3 step 4).
 */
final class ImportResult
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string,array{present:bool,rows:int,new:int,changed:int,same:int}> */
    public array $files = [];

    /** @var array<string,mixed> */
    public array $manifest = [];

    /** @var list<string> */
    public array $regionKeys = [];

    /**
     * Which template version the zip declared.
     *
     * Kept because more than one is readable from Phase 6 on, and the
     * preview should be able to say which one it read rather than leaving an
     * admin to guess why a file they expected was skipped.
     */
    public int $templateVersion = 0;

    public string $datasetVersion = '';
    public string $sha256 = '';
    public string $filename = '';
    public bool $alreadyImported = false;

    /** @var array<string,list<array<string,string>>> parsed rows, by file */
    public array $rows = [];

    public function fail(string $message): void
    {
        $this->errors[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** The first 20 validation errors are what the preview shows (Section 9.3). */
    public function firstErrors(int $limit = 20): array
    {
        return \array_slice($this->errors, 0, $limit);
    }

    public function totalNew(): int
    {
        return \array_sum(\array_column($this->files, 'new'));
    }

    public function totalChanged(): int
    {
        return \array_sum(\array_column($this->files, 'changed'));
    }
}
