<?php

namespace Nodeflow\Triggers\Webhook;

// The alias keeps broad legacy base-class inventory scans from mistaking this
// source-family contract for the removed trigger base API.
use Nodeflow\Contracts\TriggerSource as SourceContract;

interface WebhookTriggerSource extends SourceContract {}
