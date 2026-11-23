<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Enum;

/**
 * How a finished workflow ended, stored on WorkflowInstance.Outcome.
 *
 * Instances that finished before outcomes were recorded, and instances that
 * are still running, are Pending.
 */
enum WorkflowOutcome: int
{
    case Pending = 0;
    case Approved = 1;
    case Rejected = 2;

    public static function fromStored(mixed $value): WorkflowOutcome
    {
        return WorkflowOutcome::tryFrom((int)$value) ?? WorkflowOutcome::Pending;
    }
}
