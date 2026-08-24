<?php

namespace Nodeflow\Console;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/** Writes one generator kit only when every rendered PHP file survives byte-for-byte. */
final class VerifiedGeneratorWriter
{
    /** @param list<string> $allowedRoots */
    public function __construct(private Filesystem $files, private array $allowedRoots) {}

    /** @param  array<string, string>  $files */
    public function write(array $files, bool $force = false, ?callable $afterCommit = null): void
    {
        try {
            (new AtomicFileWriter($this->files))->write(
                $files,
                $this->allowedRoots,
                $force,
                fn (string $contents) => $this->assertParseable($contents),
                $afterCommit,
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage().' no registrations were changed.', previous: $e);
        }
    }

    private function assertParseable(string $contents): void
    {
        try {
            token_get_all($contents, TOKEN_PARSE);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('A generator stub did not produce parseable PHP; no files were changed.', previous: $e);
        }
    }
}
