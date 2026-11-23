<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Services;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Symbiote\AdvancedWorkflow\Actions\CancelWorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowActionInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Symbiote\AdvancedWorkflow\Enum\WorkflowOutcome;

/**
 * Records how a workflow ended and which content it approved, and answers
 * whether a record's current content is covered by an approval.
 *
 * Approval covers the record and everything it owns (for example elemental
 * blocks and linked files). A content signature built from their versions is
 * stored when the workflow completes; any later edit, addition or removal of
 * owned content changes the signature and so needs a new approval.
 */
class WorkflowApprovalService
{
    use Injectable;

    private const SIGNATURE_ALGORITHM = 'sha256';

    /**
     * Store the outcome and content signature on a workflow that has just
     * completed. The caller writes the instance.
     */
    public function recordOutcome(WorkflowInstance $instance): void
    {
        $rejected = $this->ranCancelAction($instance);
        $instance->Outcome = $rejected ? WorkflowOutcome::Rejected->value : WorkflowOutcome::Approved->value;
        $instance->ContentSignature = '';

        if ($rejected) {
            return;
        }

        $target = $instance->getTarget();

        if ($target instanceof DataObject && $target->exists()) {
            $instance->ContentSignature = $this->contentSignature($target);
        }
    }

    public function getOutcome(WorkflowInstance $instance): WorkflowOutcome
    {
        return WorkflowOutcome::fromStored($instance->Outcome);
    }

    /**
     * True when the record's most recent completed workflow approved it and
     * neither the record nor anything it owns has changed since.
     */
    public function isCurrentContentApproved(DataObject $record): bool
    {
        if (!$record->exists()) {
            return false;
        }

        $latest = WorkflowInstance::get()
            ->filter([
                'TargetClass' => array_values(ClassInfo::ancestry($record->ClassName)),
                'TargetID' => $record->ID,
                'WorkflowStatus' => WorkflowInstance::STATUS_COMPLETE,
            ])
            ->sort('ID', 'DESC')
            ->first();

        if (!$latest instanceof WorkflowInstance || $this->getOutcome($latest) !== WorkflowOutcome::Approved) {
            return false;
        }

        $signature = (string)$latest->ContentSignature;

        return $signature !== '' && hash_equals($signature, $this->contentSignature($record));
    }

    /**
     * A hash of the draft versions of the record and everything it owns,
     * recursively. Objects without versioning contribute their LastEdited.
     */
    public function contentSignature(DataObject $record): string
    {
        $parts = Versioned::withVersionedMode(function () use ($record): array {
            Versioned::set_stage(Versioned::DRAFT);
            $draft = $this->draftRecord($record);
            $parts = [$this->signaturePart($draft)];

            if ($draft->hasMethod('findOwned')) {
                foreach ($draft->findOwned(true) as $owned) {
                    $parts[] = $this->signaturePart($owned);
                }
            }

            return $parts;
        });

        $parts = array_values(array_unique($parts));
        sort($parts);

        return hash(WorkflowApprovalService::SIGNATURE_ALGORITHM, implode('|', $parts));
    }

    private function draftRecord(DataObject $record): DataObject
    {
        if (!$record->hasExtension(Versioned::class)) {
            return $record;
        }

        $draft = Versioned::get_by_stage($record->ClassName, Versioned::DRAFT)->byID($record->ID);

        return $draft instanceof DataObject ? $draft : $record;
    }

    private function signaturePart(DataObject $object): string
    {
        $revision = $object->hasExtension(Versioned::class)
            ? (string)$object->Version
            : (string)$object->LastEdited;

        return sprintf('%s#%d#%s', $object->baseClass(), $object->ID, $revision);
    }

    private function ranCancelAction(WorkflowInstance $instance): bool
    {
        $cancelActionIds = CancelWorkflowAction::get()->column('ID');

        if ($cancelActionIds === []) {
            return false;
        }

        return WorkflowActionInstance::get()
            ->filter([
                'WorkflowID' => $instance->ID,
                'BaseActionID' => $cancelActionIds,
                'Finished' => 1,
            ])
            ->exists();
    }
}
