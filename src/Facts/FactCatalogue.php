<?php

namespace Nodeflow\Facts;

use InvalidArgumentException;
use Nodeflow\Support\StableKey;

final readonly class FactCatalogue
{
    public const int EDITOR_CONTRACT_VERSION = 1;

    /** @var list<FactDefinition> */
    public array $facts;

    /** @var array<string, FactDefinition> */
    private array $byIdentity;

    /** @param list<FactDefinition> $facts */
    public function __construct(
        public string $provider,
        public string $revision,
        array $facts,
    ) {
        StableKey::assert($provider, 'fact provider key', 64);
        if ($revision === '' || strlen($revision) > 191 || preg_match('//u', $revision) !== 1) {
            throw new InvalidArgumentException('A fact catalogue revision must be a non-empty UTF-8 string of at most 191 characters.');
        }
        if (! array_is_list($facts)) {
            throw new InvalidArgumentException('A fact catalogue must contain a list of definitions.');
        }

        $byIdentity = [];
        foreach ($facts as $fact) {
            if (! $fact instanceof FactDefinition) {
                throw new InvalidArgumentException('A fact catalogue may contain only fact definitions.');
            }
            $identity = $fact->key.'@'.$fact->version;
            if (isset($byIdentity[$identity])) {
                throw new InvalidArgumentException("Duplicate fact definition [{$identity}].");
            }
            $byIdentity[$identity] = $fact;
        }
        ksort($byIdentity, SORT_STRING);

        $this->byIdentity = $byIdentity;
        $this->facts = array_values($byIdentity);
    }

    public function definition(string $key, int $version): FactDefinition
    {
        return $this->byIdentity[$key.'@'.$version]
            ?? throw new InvalidArgumentException("Fact [{$key}@{$version}] is unavailable.");
    }

    /** @return array{provider: string, revision: string, facts: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'revision' => $this->revision,
            'facts' => array_map(static fn (FactDefinition $fact): array => $fact->toArray(), $this->facts),
        ];
    }

    /** @return array{contract_version: int, revision: string, facts: list<array<string, mixed>>} */
    public function toEditorArray(): array
    {
        return [
            'contract_version' => self::EDITOR_CONTRACT_VERSION,
            'revision' => $this->revision,
            'facts' => array_map(static fn (FactDefinition $fact): array => $fact->toArray(), $this->facts),
        ];
    }
}
