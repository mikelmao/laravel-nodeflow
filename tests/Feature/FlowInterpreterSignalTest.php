<?php

use Nodeflow\Workflows\FlowInterpreter;
use Workflow\V2\Support\WorkflowDefinition;

it('registers the audienceEmptied signal that SubjectExiter fires', function () {
    expect(WorkflowDefinition::hasSignal(FlowInterpreter::class, 'audienceEmptied'))->toBeTrue();
});
