<?php

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\UniformAudienceResultValidator;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\HandlesUniformAudience;

it('defines uniform audience handling as an opt-in audience contract', function () {
    $node = new class implements HandlesUniformAudience
    {
        public function forAudience(AudienceContext $context): NodeResult
        {
            return NodeResult::empty();
        }

        public function audienceOutput(): string
        {
            return 'sent';
        }
    };

    expect(is_subclass_of(HandlesUniformAudience::class, HandlesAudience::class))->toBeTrue()
        ->and($node)->toBeInstanceOf(HandlesAudience::class)
        ->and($node->audienceOutput())->toBe('sent');
});

it('accepts the exact subject IDs under the uniform output in any order', function () {
    (new UniformAudienceResultValidator)->assertValid(
        'test.uniform',
        'message',
        'sent',
        ['sent'],
        ['3', '1', '2'],
        NodeResult::partition(['sent' => ['2', '3', '1']]),
    );

    expect(true)->toBeTrue();
});

it('rejects invalid uniform results without exposing private result content', function (
    string $expectedOutput,
    array $declaredOutputs,
    array $expectedSubjectIds,
    NodeResult $result,
    string $category,
) {
    try {
        (new UniformAudienceResultValidator)->assertValid(
            'test.uniform',
            'message',
            $expectedOutput,
            $declaredOutputs,
            $expectedSubjectIds,
            $result,
        );

        $this->fail("Expected uniform audience validation to fail with [{$category}].");
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('test.uniform')
            ->toContain('message')
            ->toContain($expectedOutput)
            ->toContain($category)
            ->not->toContain('private-subject-9981')
            ->not->toContain('private-config-api-token')
            ->not->toContain('private-failure-payment-declined');
    }
})->with([
    'blank_output' => [
        '  ',
        ['private-config-api-token'],
        ['private-subject-9981'],
        NodeResult::partition(['  ' => ['private-subject-9981']]),
        'invalid_output',
    ],
    'undeclared_output' => [
        'sent',
        ['private-config-api-token'],
        ['private-subject-9981'],
        NodeResult::partition(['sent' => ['private-subject-9981']]),
        'invalid_output',
    ],
    'failures' => [
        'sent',
        ['sent', 'private-config-api-token'],
        ['private-subject-9981'],
        NodeResult::merge(
            NodeResult::partition(['sent' => ['private-subject-9981']]),
            NodeResult::failed('private-failed-subject', 'private-failure-payment-declined'),
        ),
        'failures',
    ],
    'unexpected_output_keys' => [
        'sent',
        ['sent', 'private-config-api-token'],
        ['private-subject-9981'],
        NodeResult::partition([
            'sent' => ['private-subject-9981'],
            'private-config-api-token' => [],
        ]),
        'unexpected_output_keys',
    ],
    'duplicate_ids' => [
        'sent',
        ['sent', 'private-config-api-token'],
        ['private-subject-9981', '2'],
        NodeResult::partition(['sent' => ['private-subject-9981', 'private-subject-9981']]),
        'duplicate_ids',
    ],
    'missing_or_extra_ids' => [
        'sent',
        ['sent', 'private-config-api-token'],
        ['private-subject-9981', '2'],
        NodeResult::partition(['sent' => ['private-subject-9981', '3']]),
        'missing_or_extra_ids',
    ],
]);
