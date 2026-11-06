<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Services;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use Symbiote\AdvancedWorkflow\Actions\SimpleApprovalWorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowActionInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowTransition;
use Symbiote\AdvancedWorkflow\Services\WorkflowStatusService;

/**
 * @phpcs:disable SlevomatCodingStandard.TypeHints.PropertyTypeHint
 */
class WorkflowStatusServiceTest extends SapphireTest
{
    protected static $fixture_file = '../workflowinstancetargets.yml';

    private WorkflowStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowStatusService();
    }

    public function testGetStatusForTargetReturnsNullWhenNoWorkflow(): void
    {
        $result = $this->service->getStatusForTarget(99999, 'SilverStripe\\CMS\\Model\\SiteTree');

        $this->assertNull($result);
    }

    public function testGetStatusForTargetReturnsData(): void
    {
        $setup = $this->createWorkflowForTarget(42, 'SilverStripe\\CMS\\Model\\SiteTree');

        $result = $this->service->getStatusForTarget(42, 'SilverStripe\\CMS\\Model\\SiteTree');

        $this->assertNotNull($result);
        $this->assertSame('Active', $result['workflowStatus']);
        $this->assertArrayHasKey('steps', $result);
        $this->assertArrayHasKey('currentIndex', $result);
        $this->assertArrayHasKey('nextTransitions', $result);
        $this->assertArrayHasKey('backTransitions', $result);
        $this->assertArrayHasKey('history', $result);
        $this->assertArrayHasKey('instanceId', $result);
    }

    public function testGetStatusForTargetIncludesSteps(): void
    {
        $this->createWorkflowForTarget(43, 'SilverStripe\\CMS\\Model\\SiteTree');

        $result = $this->service->getStatusForTarget(43, 'SilverStripe\\CMS\\Model\\SiteTree');

        // The service presents editor-facing phases (Submitted -> approval
        // steps -> outcome), not the raw automation actions.
        $this->assertCount(3, $result['steps']);
        $this->assertSame('Submitted', $result['steps'][0]['title']);
        $this->assertSame('Review', $result['steps'][1]['title']);
        $this->assertSame('Approved', $result['steps'][2]['title']);
    }

    public function testGetStatusForTargetClassifiesTransitions(): void
    {
        $setup = $this->createWorkflowForTarget(44, 'SilverStripe\\CMS\\Model\\SiteTree');

        $result = $this->service->getStatusForTarget(44, 'SilverStripe\\CMS\\Model\\SiteTree');

        $this->assertNotEmpty($result['nextTransitions']);
        $this->assertNotEmpty($result['backTransitions']);

        $nextTitles = array_column($result['nextTransitions'], 'title');
        $backTitles = array_column($result['backTransitions'], 'title');

        $this->assertContains('Approve', $nextTitles);
        $this->assertContains('Reject', $backTitles);
    }

    public function testGetAssignmentMetaReturnsGroupsAndUsers(): void
    {
        $meta = $this->service->getAssignmentMeta();

        $this->assertArrayHasKey('groups', $meta);
        $this->assertArrayHasKey('users', $meta);
        $this->assertIsArray($meta['groups']);
        $this->assertIsArray($meta['users']);
    }

    public function testGetAssignmentMetaDoesNotPreloadUsers(): void
    {
        Member::create(['FirstName' => 'Preload', 'Email' => 'preload@example.com'])->write();

        // Users must be searched on demand, not shipped in the meta payload.
        $this->assertSame([], $this->service->getAssignmentMeta()['users']);
    }

    public function testSearchUsersMatchesNameAndEmail(): void
    {
        $alice = Member::create(['FirstName' => 'Alicexyz', 'Surname' => 'Smith', 'Email' => 'alicexyz@example.com']);
        $alice->write();
        $bob = Member::create(['FirstName' => 'Bobxyz', 'Surname' => 'Jones', 'Email' => 'bobxyz@example.com']);
        $bob->write();

        $byName = $this->service->searchUsers('Alicexyz');
        $nameIds = array_column($byName, 'id');
        $this->assertContains((int)$alice->ID, $nameIds);
        $this->assertNotContains((int)$bob->ID, $nameIds);
        $this->assertArrayHasKey('name', $byName[0]);
        $this->assertStringContainsString('Alicexyz', $byName[0]['name']);

        $byEmail = $this->service->searchUsers('bobxyz@example');
        $this->assertContains((int)$bob->ID, array_column($byEmail, 'id'));
    }

    public function testGetUserLabelsResolvesSelectedIds(): void
    {
        $carol = Member::create(['FirstName' => 'Carolxyz', 'Surname' => 'Lee', 'Email' => 'carolxyz@example.com']);
        $carol->write();

        $labels = $this->service->getUserLabels([(int)$carol->ID]);
        $this->assertArrayHasKey((int)$carol->ID, $labels);
        $this->assertStringContainsString('Carolxyz', $labels[(int)$carol->ID]);
        $this->assertSame([], $this->service->getUserLabels([]));
    }

    public function testAddCommentByIdThrowsForInvalidInstance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow instance not found');

        $this->service->addCommentById(99999, 'test comment');
    }

    public function testExecuteTransitionByIdThrowsForInvalidTransition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Transition not found');

        $this->service->executeTransitionById(99999);
    }

    public function testGetStatusReturnsCurrentStepTitle(): void
    {
        $this->createWorkflowForTarget(45, 'SilverStripe\\CMS\\Model\\SiteTree');

        $result = $this->service->getStatusForTarget(45, 'SilverStripe\\CMS\\Model\\SiteTree');

        $this->assertNotNull($result['current']);
        $this->assertSame('Review', $result['current']);
    }

    public function testGetStatusReturnsHistoryArray(): void
    {
        $setup = $this->createWorkflowForTarget(46, 'SilverStripe\\CMS\\Model\\SiteTree');

        $finishedAction = WorkflowActionInstance::create();
        $finishedAction->WorkflowID = $setup['instance']->ID;
        $finishedAction->BaseActionID = $setup['actions'][0]->ID;
        $finishedAction->Finished = true;
        $finishedAction->Comment = 'Initial submission';
        $finishedAction->write();

        $result = $this->service->getStatusForTarget(46, 'SilverStripe\\CMS\\Model\\SiteTree');

        $this->assertIsArray($result['history']);
        $this->assertGreaterThanOrEqual(1, count($result['history']));
        $this->assertSame('Initial submission', $result['history'][0]['comment']);
    }

    /**
     * Helper to create a workflow definition + instance for testing.
     *
     * @return array{instance: WorkflowInstance, actions: WorkflowAction[], transitions: WorkflowTransition[]}
     */
    private function createWorkflowForTarget(int $targetId, string $targetClass): array
    {
        $definition = WorkflowDefinition::create();
        $definition->Title = 'Test Workflow';
        $definition->write();

        $draft = WorkflowAction::create();
        $draft->Title = 'Draft';
        $draft->Sort = 0;
        $draft->WorkflowDefID = $definition->ID;
        $draft->write();

        // Review is an approval step so the status service surfaces it as a
        // user-facing phase (buildPhases only promotes approval actions).
        $review = SimpleApprovalWorkflowAction::create();
        $review->Title = 'Review';
        $review->Sort = 1;
        $review->WorkflowDefID = $definition->ID;
        $review->write();

        $publish = WorkflowAction::create();
        $publish->Title = 'Publish';
        $publish->Sort = 2;
        $publish->WorkflowDefID = $definition->ID;
        $publish->write();

        $approveTransition = WorkflowTransition::create();
        $approveTransition->Title = 'Approve';
        $approveTransition->ActionID = $review->ID;
        $approveTransition->NextActionID = $publish->ID;
        $approveTransition->write();

        $rejectTransition = WorkflowTransition::create();
        $rejectTransition->Title = 'Reject';
        $rejectTransition->ActionID = $review->ID;
        $rejectTransition->NextActionID = $draft->ID;
        $rejectTransition->write();

        $currentActionInstance = WorkflowActionInstance::create();
        $currentActionInstance->BaseActionID = $review->ID;
        $currentActionInstance->Finished = false;
        $currentActionInstance->write();

        $instance = WorkflowInstance::create();
        $instance->Title = 'Test Instance';
        $instance->WorkflowStatus = 'Active';
        $instance->TargetClass = $targetClass;
        $instance->TargetID = $targetId;
        $instance->DefinitionID = $definition->ID;
        $instance->CurrentActionID = $currentActionInstance->ID;
        $instance->write();

        $currentActionInstance->WorkflowID = $instance->ID;
        $currentActionInstance->write();

        return [
            'instance' => $instance,
            'actions' => [$draft, $review, $publish],
            'transitions' => [$approveTransition, $rejectTransition],
        ];
    }
}
