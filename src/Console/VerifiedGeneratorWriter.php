<?php

namespace Nodeflow\Console;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Throwable;

/** Writes one generator kit only when every rendered PHP file survives byte-for-byte. */
final class VerifiedGeneratorWriter
{
    /** @param list<string> $allowedRoots */
    public function __construct(private Filesystem $files, private array $allowedRoots) {}

    /** @param  array<string, string>  $files */
    public function write(array $files, bool $force = false, ?NodeRegistrationPlan $registration = null): void
    {
        try {
            $alwaysReplace = [];
            $expectedOriginals = [];
            $guards = [];
            if ($registration?->outcome === NodeRegistrationOutcome::Appended) {
                if ($registration->path === null || $registration->contents === null || $registration->originalContents === null || array_key_exists($registration->path, $files)) {
                    throw new InvalidArgumentException('The provider registration plan is incomplete or conflicts with a generated artifact.');
                }
                $files[$registration->path] = $registration->contents;
                $alwaysReplace[] = $registration->path;
                $expectedOriginals[$registration->path] = $registration->originalContents;
            } elseif ($registration?->outcome === NodeRegistrationOutcome::AlreadyPresent) {
                if ($registration->path === null || $registration->contents === null || $registration->originalContents === null) {
                    throw new InvalidArgumentException('The existing provider registration plan is incomplete.');
                }
                $guards[$registration->path] = [
                    'contents' => $registration->originalContents,
                    'validator' => function (string $contents, string $path) use ($registration): void {
                        $registration->validate($contents, $path);
                    },
                ];
            }

            (new AtomicFileWriter($this->files))->write(
                $files,
                $this->allowedRoots,
                $force,
                function (string $contents, string $path) use ($registration): void {
                    if ($registration?->outcome === NodeRegistrationOutcome::Appended && $registration->path === $path) {
                        $registration->validate($contents, $path);

                        return;
                    }
                    $this->assertParseable($contents);
                },
                $alwaysReplace,
                $expectedOriginals,
                $guards,
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage().' generation transaction failed.', previous: $e);
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
