<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Appends one SubjectAttribute to the host provider's subjectAttributes().
 *
 * Thin on purpose, and it still earns its place: a core.condition can only
 * reference attributes the host registered, so conditions — the non-technical
 * author's main tool — are bounded entirely by this registry, and the registry
 * has no documentation page of its own. It is the least discoverable part of the
 * package.
 *
 * Writes no file. The entry is rendered fully qualified, exactly as node entries
 * are, so it can be appended into a provider whose use block this command never
 * touches — a use-block insertion has no anchor that could be proven.
 */
class MakeSubjectAttributeCommand extends Command
{
    protected $signature = 'nodeflow:make-subject-attribute
        {key : The attribute key a condition will reference, e.g. clicked_offer}
        {--label= : The label shown in the editor; derived from the key when omitted}
        {--type=boolean : boolean, text or number}';

    protected $description = 'Register a Nodeflow subject attribute in your provider.';

    /**
     * The three the registry's comparisons coerce. A fourth value produces an
     * attribute whose comparisons behave arbitrarily inside an already-published
     * graph, which is not a failure a host can see coming.
     */
    private const TYPES = ['boolean', 'text', 'number'];

    /** As tight as an output name: this key is stored inside published graphs. */
    private const KEY_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    public function handle(): int
    {
        // Nothing is cached on this instance across invocations, so there is
        // nothing to reset here. Left as an explicit note rather than silence,
        // because two earlier commands in this package shipped exactly that
        // leak: Symfony resolves one command object per name and keeps it for
        // the process's lifetime, so a stray cached property from a first
        // handle() would otherwise survive into a second, unrelated one.
        $key = trim((string) $this->argument('key'));
        $type = strtolower(trim((string) $this->option('type')));
        $label = trim((string) $this->option('label')) ?: Str::ucfirst(str_replace('_', ' ', $key));

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            $this->components->error(
                "[{$key}] is not a valid attribute key. Use lowercase letters, digits and "
                .'underscores, e.g. clicked_offer. A published graph stores this key and '
                .'resolves through it, so it stays conservative.'
            );

            return self::FAILURE;
        }

        if (! in_array($type, self::TYPES, true)) {
            $this->components->error(
                "[{$type}] is not a supported attribute type. Use ".implode(', ', self::TYPES)
                .'. The type drives how a condition coerces its comparison, so a value '
                .'outside this set produces comparisons that behave arbitrarily at runtime.'
            );

            return self::FAILURE;
        }

        $entry = sprintf(
            "\\Nodeflow\\Schema\\SubjectAttribute::make('%s', '%s', '%s',\n"
            ."                // TODO: return this attribute's value for one subject.\n"
            .'                fn ($subject) => null)',
            $key,
            addcslashes($label, '\\\''),
            $type,
        );

        $outcome = $this->laravel->make(NodeRegistrationWriter::class)->appendTo(
            $this->laravel->basePath('app/Providers/NodeflowServiceProvider.php'),
            NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
            "SubjectAttribute::make('{$key}'",
            $entry,
            '            ',
        );

        match ($outcome) {
            NodeRegistrationOutcome::Appended => $this->components->info(
                "Registered [{$key}] in app/Providers/NodeflowServiceProvider.php. "
                .'Fill in the resolver closure before a condition uses it.'
            ),
            NodeRegistrationOutcome::AlreadyPresent => $this->components->info(
                "Already registered: [{$key}] is in app/Providers/NodeflowServiceProvider.php."
            ),
            NodeRegistrationOutcome::ProviderMissing => $this->manual($entry,
                'No app/Providers/NodeflowServiceProvider.php found. Run `php artisan nodeflow:install`.'
            ),
            NodeRegistrationOutcome::AnchorMissing => $this->manual($entry,
                'app/Providers/NodeflowServiceProvider.php has no `'
                .NodeRegistrationWriter::ATTRIBUTE_ANCHOR.'` method with a bare `return [` body.'
            ),
            NodeRegistrationOutcome::AnchorAmbiguous => $this->manual($entry,
                'app/Providers/NodeflowServiceProvider.php has more than one `'
                .NodeRegistrationWriter::ATTRIBUTE_ANCHOR.'` line.'
            ),
            NodeRegistrationOutcome::WriteFailed => $this->manual($entry,
                'The automatic edit to app/Providers/NodeflowServiceProvider.php did not '
                .'produce valid PHP — the `'.NodeRegistrationWriter::ATTRIBUTE_ANCHOR.'` method '
                .'may be commented out.'
            ),
        };

        // Generating nothing is still success: the command's contract is "register
        // it if I can prove where, otherwise tell you exactly what to paste". Only
        // a usage error is a failure.
        return self::SUCCESS;
    }

    private function manual(string $entry, string $because): void
    {
        $this->components->warn($because.' Register the attribute yourself:');
        $this->newLine();
        $this->line('    app(\\Nodeflow\\Schema\\SubjectAttributeRegistry::class)->register(');
        $this->line('        '.$entry.',');
        $this->line('    );');
        $this->newLine();
    }
}
