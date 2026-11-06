<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Enum;

use Symbiote\AdvancedWorkflow\Actions\AssignUsersToWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\CancelWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\NotifyUsersWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\PublishItemWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\SetPropertyWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\SimpleApprovalWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\UnpublishItemWorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;

/**
 * Maps a visual-builder step type to its concrete WorkflowAction subclass and
 * back. This is the single source of truth that lets the builder persist real,
 * executable action subclasses (rather than bare WorkflowAction records) and
 * reload them without losing their identity.
 */
enum WorkflowStepType: string
{
    case Approval = 'approval';
    case Notification = 'notification';
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case Assign = 'assign';
    case SetProperty = 'setproperty';
    case Cancel = 'cancel';

    /**
     * The concrete WorkflowAction subclass this step type persists as.
     *
     * @return class-string<WorkflowAction>
     */
    public function actionClass(): string
    {
        return match ($this) {
            self::Approval => SimpleApprovalWorkflowAction::class,
            self::Notification => NotifyUsersWorkflowAction::class,
            self::Publish => PublishItemWorkflowAction::class,
            self::Unpublish => UnpublishItemWorkflowAction::class,
            self::Assign => AssignUsersToWorkflowAction::class,
            self::SetProperty => SetPropertyWorkflowAction::class,
            self::Cancel => CancelWorkflowAction::class,
        };
    }

    /**
     * Human-readable label for the builder UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Approval => 'Approval',
            self::Notification => 'Notification',
            self::Publish => 'Publish',
            self::Unpublish => 'Unpublish',
            self::Assign => 'Assign Users',
            self::SetProperty => 'Set Property',
            self::Cancel => 'Reject / Cancel',
        };
    }

    /**
     * Whether this step branches (i.e. has distinct approve/reject outcomes).
     * Only approval steps block and wait on multiple outbound transitions.
     */
    public function supportsBranches(): bool
    {
        return $this === self::Approval;
    }

    /**
     * Whether this step assigns users/groups (has the many_many relations).
     */
    public function assignsUsers(): bool
    {
        return $this === self::Assign;
    }

    /**
     * Resolve the builder step type for a stored WorkflowAction instance/class.
     * Falls back to Approval for unknown subclasses so an unrecognised action is
     * still represented (and never silently dropped).
     *
     * @param class-string<WorkflowAction>|string $className
     */
    public static function fromActionClass(string $className): self
    {
        // Exact match first — cheap and avoids autoloading.
        foreach (self::cases() as $case) {
            if ($case->actionClass() === $className) {
                return $case;
            }
        }

        // Fall back to inheritance so project-specific subclasses map to their
        // nearest known base type (most specific base classes checked first).
        $bySpecificity = [
            self::Assign,
            self::Notification,
            self::Publish,
            self::Unpublish,
            self::SetProperty,
            self::Cancel,
            self::Approval,
        ];

        foreach ($bySpecificity as $case) {
            if (is_a($className, $case->actionClass(), true)) {
                return $case;
            }
        }

        return self::Approval;
    }

    /**
     * Resolve a builder step type from its string value, defaulting to Approval
     * for empty or unknown values.
     */
    public static function fromValue(?string $type): self
    {
        return self::tryFrom((string)$type) ?? self::Approval;
    }
}
