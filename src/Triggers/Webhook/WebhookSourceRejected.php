<?php

namespace Nodeflow\Triggers\Webhook;

use RuntimeException;

/**
 * A host source may throw this to reject an authenticated delivery as invalid.
 * Its message is never returned to the sender or reported by the package.
 */
class WebhookSourceRejected extends RuntimeException {}
