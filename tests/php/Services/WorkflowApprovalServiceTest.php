<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Services;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Symbiote\AdvancedWorkflow\Actions\CancelWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\SimpleApprovalWorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowTransition;
use Symbiote\AdvancedWorkflow\Enum\WorkflowOutcome;
use Symbiote\AdvancedWorkflow\Services\WorkflowApprovalService;
use Symbiote\AdvancedWorkflow\Services\WorkflowService;
use Symbiote\AdvancedWorkflow\Tests\Services\WorkflowApprovalServiceTest\ApprovalTestBlock;
use Symbiote\AdvancedWorkflow\Tests\Services\WorkflowApprovalServiceTest\ApprovalTestPage;

/**
 * Runs workflows through the engine and checks the outcome and approved
 * content it records.
 *
 * @phpcs:disable SlevomatCodingStandard.TypeHints.PropertyTypeHint
 */
class WorkflowApprovalServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        ApprovalTestPage::class,
        ApprovalTestBlock::class,
    ];

    private const APPROVE = 'Approve';

    private const REJECT = 'Reject';

    private WorkflowApprovalService $service;

    private WorkflowDefinition $definition;

    private ApprovalTestPage $page;

    private ApprovalTestBlock $block;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logInWithPermission('ADMIN');
        $this->service = WorkflowApprovalService::create();
        $this->definition = $this->createReviewDefinition();

        $this->page = ApprovalTestPage::create([
            'Title' => 'Reviewed page',
            'WorkflowDefinitionID' => $this->definition->ID,
        ]);
        $this->page->write();

        $this->block = ApprovalTestBlock::create(['Content' => 'Original', 'PageID' => $this->page->ID]);
        $this->block->write();
    }

    public function testApprovedWorkflowRecordsApprovedOutcome(): void
    {
        $instance = $this->runReview(WorkflowApprovalServiceTest::APPROVE);

        $this->assertSame(WorkflowInstance::STATUS_COMPLETE, $instance->WorkflowStatus);
        $this->assertSame(WorkflowOutcome::Approved, $this->service->getOutcome($instance));
        $this->assertNotSame('', (string)$instance->ContentSignature);
    }

    public function testRejectedWorkflowRecordsRejectedOutcome(): void
    {
        $instance = $this->runReview(WorkflowApprovalServiceTest::REJECT);

        $this->assertSame(WorkflowInstance::STATUS_COMPLETE, $instance->WorkflowStatus);
        $this->assertSame(WorkflowOutcome::Rejected, $this->service->getOutcome($instance));
        $this->assertFalse($this->service->isCurrentContentApproved($this->page));
    }

    public function testRejectionIsNotInferredFromStepTitles(): void
    {
        WorkflowAction::get()->filter('Title', 'Done')->first()->update(['Title' => 'Cancel embargo'])->write();

        $instance = $this->runReview(WorkflowApprovalServiceTest::APPROVE);

        $this->assertSame(WorkflowOutcome::Approved, $this->service->getOutcome($instance));
    }

    public function testUnchangedContentStaysApproved(): void
    {
        $this->runReview(WorkflowApprovalServiceTest::APPROVE);

        $this->assertTrue($this->service->isCurrentContentApproved($this->reloadPage()));
    }

    public function testEditingThePageRevokesApproval(): void
    {
        $this->runReview(WorkflowApprovalServiceTest::APPROVE);

        $page = $this->reloadPage();
        $page->Title = 'Changed after approval';
        $page->write();

        $this->assertFalse($this->service->isCurrentContentApproved($page));
    }

    public function testEditingAnOwnedBlockRevokesApproval(): void
    {
        $this->runReview(WorkflowApprovalServiceTest::APPROVE);

        $this->block->Content = 'Changed after approval';
        $this->block->write();

        $this->assertFalse($this->service->isCurrentContentApproved($this->reloadPage()));
    }

    public function testAddingAnOwnedBlockRevokesApproval(): void
    {
        $this->runReview(WorkflowApprovalServiceTest::APPROVE);

        ApprovalTestBlock::create(['Content' => 'New', 'PageID' => $this->page->ID])->write();

        $this->assertFalse($this->service->isCurrentContentApproved($this->reloadPage()));
    }

    public function testRemovingAnOwnedBlockRevokesApproval(): void
    {
        $this->runReview(WorkflowApprovalServiceTest::APPROVE);

        $this->block->doArchive();

        $this->assertFalse($this->service->isCurrentContentApproved($this->reloadPage()));
    }

    public function testRecordWithoutWorkflowIsNotApproved(): void
    {
        $this->assertFalse($this->service->isCurrentContentApproved($this->page));
    }

    public function testOnlyTheLatestWorkflowCounts(): void
    {
        $this->runReview(WorkflowApprovalServiceTest::APPROVE);
        $this->runReview(WorkflowApprovalServiceTest::REJECT);

        $this->assertFalse($this->service->isCurrentContentApproved($this->reloadPage()));
    }

    /**
     * Submit (automatic) → Review, which either approves (Done) or rejects
     * (a CancelWorkflowAction).
     */
    private function createReviewDefinition(): WorkflowDefinition
    {
        $definition = WorkflowDefinition::create(['Title' => 'Review']);
        $definition->write();

        $submit = $this->createAction(WorkflowAction::class, 'Submit', $definition, 1);
        $review = $this->createAction(SimpleApprovalWorkflowAction::class, 'Review', $definition, 2);
        $done = $this->createAction(WorkflowAction::class, 'Done', $definition, 3);
        $cancel = $this->createAction(CancelWorkflowAction::class, 'Rejected', $definition, 4);

        $this->createTransition($submit, $review, 'Submit for review');
        $this->createTransition($review, $done, WorkflowApprovalServiceTest::APPROVE);
        $this->createTransition($review, $cancel, WorkflowApprovalServiceTest::REJECT);

        return $definition;
    }

    /**
     * @param class-string<WorkflowAction> $class
     */
    private function createAction(
        string $class,
        string $title,
        WorkflowDefinition $definition,
        int $sort
    ): WorkflowAction {
        $action = $class::create(['Title' => $title, 'WorkflowDefID' => $definition->ID, 'Sort' => $sort]);
        $action->write();

        return $action;
    }

    private function createTransition(WorkflowAction $from, WorkflowAction $to, string $title): void
    {
        WorkflowTransition::create([
            'Title' => $title,
            'ActionID' => $from->ID,
            'NextActionID' => $to->ID,
        ])->write();
    }

    private function runReview(string $decision): WorkflowInstance
    {
        $page = $this->reloadPage();
        Injector::inst()->get(WorkflowService::class)->startWorkflow($page, $this->definition->ID);

        $instance = WorkflowInstance::get()->filter('TargetID', $page->ID)->sort('ID', 'DESC')->first();
        $transition = WorkflowTransition::get()->filter('Title', $decision)->first();
        $instance->performTransition($transition);

        return WorkflowInstance::get()->byID($instance->ID);
    }

    private function reloadPage(): ApprovalTestPage
    {
        return ApprovalTestPage::get()->byID($this->page->ID);
    }
}
