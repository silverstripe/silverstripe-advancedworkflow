<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Services;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use Symbiote\AdvancedWorkflow\Actions\AssignUsersToWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\CancelWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\NotifyUsersWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\PublishItemWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\SimpleApprovalWorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowTransition;
use Symbiote\AdvancedWorkflow\Services\WorkflowBuilderService;

/**
 * @phpcs:disable SlevomatCodingStandard.TypeHints.PropertyTypeHint
 */
class WorkflowBuilderServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    private WorkflowBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowBuilderService();
    }

    public function testSaveDefinitionCreatesNewDefinition(): void
    {
        $result = $this->service->saveDefinition([
            'title' => 'Test Workflow',
            'steps' => [
                ['id' => 'step-1', 'title' => 'Review', 'type' => 'approval', 'branches' => []],
                ['id' => 'step-2', 'title' => 'Publish', 'type' => 'publish', 'branches' => []],
            ],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThan(0, $result['id']);

        $definition = WorkflowDefinition::get()->byID($result['id']);
        $this->assertSame('Test Workflow', $definition->Title);
        $this->assertCount(2, $definition->Actions());
    }

    /**
     * Each step must persist as its real, executable WorkflowAction subclass —
     * not a bare base WorkflowAction (which would be a no-op at runtime).
     */
    public function testStepsPersistAsRealSubclasses(): void
    {
        $result = $this->service->saveDefinition([
            'title' => 'Typed',
            'steps' => [
                ['id' => 'a', 'title' => 'Assign', 'type' => 'assign', 'branches' => []],
                ['id' => 'n', 'title' => 'Notify', 'type' => 'notification', 'branches' => []],
                ['id' => 'r', 'title' => 'Review', 'type' => 'approval', 'branches' => []],
                ['id' => 'p', 'title' => 'Publish', 'type' => 'publish', 'branches' => []],
                ['id' => 'c', 'title' => 'Cancel', 'type' => 'cancel', 'branches' => []],
            ],
        ]);

        // column() returns values in Sort order, indexed by position (not by
        // the Sort value itself), so it's robust to the 1-based Sort numbering.
        $classes = WorkflowDefinition::get()->byID($result['id'])->Actions()->sort('Sort')->column('ClassName');

        $this->assertSame(AssignUsersToWorkflowAction::class, $classes[0]);
        $this->assertSame(NotifyUsersWorkflowAction::class, $classes[1]);
        $this->assertSame(SimpleApprovalWorkflowAction::class, $classes[2]);
        $this->assertSame(PublishItemWorkflowAction::class, $classes[3]);
        $this->assertSame(CancelWorkflowAction::class, $classes[4]);
    }

    /**
     * Regression: WorkflowAction::onBeforeWrite auto-assigns MAX(Sort)+1 when
     * Sort is falsy (0). Saving must use 1-based Sort so step order is stable
     * across repeated saves and never collapses to a table-wide max.
     */
    public function testSavePreservesStepOrderAcrossRepeatedSaves(): void
    {
        // Seed unrelated actions so a table-wide MAX(Sort) is large.
        $other = WorkflowDefinition::create(['Title' => 'Other']);
        $other->write();
        $noise = SimpleApprovalWorkflowAction::create(['Title' => 'Noise', 'Sort' => 50]);
        $noise->WorkflowDefID = $other->ID;
        $noise->write();

        $data = [
            'title' => 'Ordered',
            'steps' => [
                ['id' => 'a', 'title' => 'First', 'type' => 'assign', 'branches' => []],
                ['id' => 'b', 'title' => 'Second', 'type' => 'notification', 'branches' => []],
                ['id' => 'c', 'title' => 'Third', 'type' => 'approval', 'branches' => []],
            ],
        ];

        $first = $this->service->saveDefinition($data);
        $definitionId = $first['id'];

        // Re-save what the builder would send back after a reload, twice.
        for ($i = 0; $i < 2; $i++) {
            $this->service->saveDefinition($this->service->loadDefinition($definitionId));
        }

        $actions = WorkflowDefinition::get()->byID($definitionId)->Actions()->sort('Sort');
        $this->assertSame(['First', 'Second', 'Third'], $actions->column('Title'));
        $this->assertGreaterThanOrEqual(1, (int)$actions->min('Sort'), 'Sort must never be 0');
    }

    public function testSaveDefinitionCreatesBranchTransitions(): void
    {
        $result = $this->service->saveDefinition([
            'title' => 'Transition Test',
            'steps' => [
                ['id' => 'a', 'title' => 'Start', 'type' => 'notification', 'branches' => []],
                [
                    'id' => 'b',
                    'title' => 'Review',
                    'type' => 'approval',
                    'branches' => [
                        'approve' => ['nextStepId' => 'c'],
                        'reject' => ['nextStepId' => 'a'],
                    ],
                ],
                ['id' => 'c', 'title' => 'Done', 'type' => 'publish', 'branches' => []],
            ],
        ]);

        $review = WorkflowDefinition::get()->byID($result['id'])->Actions()->sort('Sort')->offsetGet(1);
        $titles = $review->Transitions()->column('Title');

        $this->assertCount(2, $review->Transitions());
        $this->assertContains('Approve', $titles);
        $this->assertContains('Reject', $titles);
    }

    /**
     * Saving must update actions in place, never delete-and-recreate — so
     * action identity (ID) is stable across saves.
     */
    public function testSaveIsNonDestructiveOfActionIdentity(): void
    {
        $create = $this->service->saveDefinition([
            'title' => 'Identity',
            'steps' => [['id' => 'a', 'title' => 'Review', 'type' => 'approval', 'branches' => []]],
        ]);

        $definitionId = $create['id'];
        $originalId = (int)WorkflowDefinition::get()->byID($definitionId)->Actions()->first()->ID;

        // Reload as the builder would, then save again unchanged.
        $loaded = $this->service->loadDefinition($definitionId);
        $this->service->saveDefinition($loaded);

        $actions = WorkflowDefinition::get()->byID($definitionId)->Actions();
        $this->assertCount(1, $actions);
        $this->assertSame($originalId, (int)$actions->first()->ID, 'Action should be updated in place, not recreated');
    }

    /**
     * Config the builder does not itself edit (e.g. an email template authored
     * in the legacy editor) must survive a builder save.
     */
    public function testSavePreservesUntouchedActionConfig(): void
    {
        $definition = WorkflowDefinition::create(['Title' => 'Preserve']);
        $definition->write();

        $notify = NotifyUsersWorkflowAction::create();
        $notify->Title = 'Notify';
        $notify->EmailSubject = 'Original subject';
        $notify->EmailTemplate = 'Original body';
        $notify->Sort = 0;
        $notify->WorkflowDefID = $definition->ID;
        $notify->write();

        // Builder saves the step back WITHOUT any email config in its payload.
        $this->service->saveDefinition([
            'id' => $definition->ID,
            'title' => 'Preserve',
            'steps' => [['id' => (string)$notify->ID, 'title' => 'Notify', 'type' => 'notification', 'branches' => []]],
        ]);

        $reloaded = NotifyUsersWorkflowAction::get()->byID($notify->ID);
        $this->assertSame('Original subject', $reloaded->EmailSubject);
        $this->assertSame('Original body', $reloaded->EmailTemplate);
    }

    /**
     * Changing a step's type converts the underlying record's class in place,
     * keeping the same ID.
     */
    public function testChangingStepTypeConvertsActionClass(): void
    {
        $create = $this->service->saveDefinition([
            'title' => 'Convert',
            'steps' => [['id' => 'a', 'title' => 'Step', 'type' => 'approval', 'branches' => []]],
        ]);

        $definitionId = $create['id'];
        $actionId = (int)WorkflowDefinition::get()->byID($definitionId)->Actions()->first()->ID;

        $this->service->saveDefinition([
            'id' => $definitionId,
            'title' => 'Convert',
            'steps' => [['id' => (string)$actionId, 'title' => 'Step', 'type' => 'publish', 'branches' => []]],
        ]);

        $action = WorkflowAction::get()->byID($actionId);
        $this->assertInstanceOf(PublishItemWorkflowAction::class, $action);
        $this->assertSame($actionId, (int)$action->ID);
    }

    public function testAssignStepPersistsGroupsAndUsers(): void
    {
        $group = Group::create(['Title' => 'Reviewers']);
        $group->write();
        $member = Member::create(['Email' => 'reviewer@example.com']);
        $member->write();

        $create = $this->service->saveDefinition([
            'title' => 'Assign',
            'steps' => [[
                'id' => 'a',
                'title' => 'Assign',
                'type' => 'assign',
                'branches' => [],
                'config' => [
                    'groupIds' => [$group->ID],
                    'userIds' => [$member->ID],
                    'assignInitiator' => true,
                ],
            ]],
        ]);

        /** @var AssignUsersToWorkflowAction $action */
        $action = WorkflowDefinition::get()->byID($create['id'])->Actions()->first();
        $this->assertTrue($action->Groups()->filter('ID', $group->ID)->exists(), 'group assigned');
        $this->assertTrue($action->Users()->filter('ID', $member->ID)->exists(), 'user assigned');
        $this->assertTrue((bool)$action->AssignInitiator);

        // And it round-trips through load (IDs are ints in the contract).
        $loaded = $this->service->loadDefinition($create['id']);
        $this->assertContains((int)$group->ID, $loaded['steps'][0]['config']['groupIds']);
    }

    public function testNotificationConfigPersistsAndRoundTrips(): void
    {
        $create = $this->service->saveDefinition([
            'title' => 'Notify config',
            'steps' => [[
                'id' => 'n',
                'title' => 'Notify',
                'type' => 'notification',
                'branches' => [],
                'config' => [
                    'emailSubject' => 'Please review $Title',
                    'emailFrom' => 'cms@example.com',
                    'emailTemplate' => 'Hello',
                ],
            ]],
        ]);

        /** @var NotifyUsersWorkflowAction $action */
        $action = WorkflowDefinition::get()->byID($create['id'])->Actions()->first();
        $this->assertSame('Please review $Title', $action->EmailSubject);
        $this->assertSame('cms@example.com', $action->EmailFrom);

        $loaded = $this->service->loadDefinition($create['id']);
        $this->assertSame('Please review $Title', $loaded['steps'][0]['config']['emailSubject']);
    }

    /**
     * An existing transition's config (Type, restrictions) is preserved across a
     * branches-based save because transitions are matched by title, not recreated.
     */
    public function testExistingTransitionConfigPreservedByTitleMatch(): void
    {
        $definition = WorkflowDefinition::create(['Title' => 'Trans preserve']);
        $definition->write();

        $review = SimpleApprovalWorkflowAction::create(['Title' => 'Review', 'Sort' => 0]);
        $review->WorkflowDefID = $definition->ID;
        $review->write();

        $publish = PublishItemWorkflowAction::create(['Title' => 'Publish', 'Sort' => 1]);
        $publish->WorkflowDefID = $definition->ID;
        $publish->write();

        $transition = WorkflowTransition::create(['Title' => 'Approve', 'Type' => 'Passive']);
        $transition->ActionID = $review->ID;
        $transition->NextActionID = $publish->ID;
        $transition->write();
        $transitionId = (int)$transition->ID;

        $this->service->saveDefinition([
            'id' => $definition->ID,
            'title' => 'Trans preserve',
            'steps' => [
                [
                    'id' => (string)$review->ID,
                    'title' => 'Review',
                    'type' => 'approval',
                    'branches' => ['approve' => ['nextStepId' => (string)$publish->ID]],
                ],
                ['id' => (string)$publish->ID, 'title' => 'Publish', 'type' => 'publish', 'branches' => []],
            ],
        ]);

        $reloaded = WorkflowTransition::get()->byID($transitionId);
        $this->assertNotNull($reloaded, 'Transition should be reused, not deleted and recreated');
        $this->assertSame('Passive', $reloaded->Type, 'Transition Type must be preserved');
    }

    public function testRemovedStepAndItsTransitionsAreDeleted(): void
    {
        $create = $this->service->saveDefinition([
            'title' => 'Removal',
            'steps' => [
                [
                    'id' => 'a',
                    'title' => 'Review',
                    'type' => 'approval',
                    'branches' => ['approve' => ['nextStepId' => 'b']],
                ],
                ['id' => 'b', 'title' => 'Publish', 'type' => 'publish', 'branches' => []],
            ],
        ]);

        $definitionId = $create['id'];
        $transitionCountBefore = WorkflowTransition::get()->count();
        $this->assertGreaterThan(0, $transitionCountBefore);

        $loaded = $this->service->loadDefinition($definitionId);
        // Keep only the first step.
        $loaded['steps'] = [array_merge($loaded['steps'][0], ['branches' => [], 'transitions' => []])];
        $this->service->saveDefinition($loaded);

        $this->assertCount(1, WorkflowDefinition::get()->byID($definitionId)->Actions());
        $this->assertSame(0, WorkflowTransition::get()->count(), 'Orphaned transitions must be deleted');
    }

    public function testDefinitionMetadataPersists(): void
    {
        $group = Group::create(['Title' => 'Editors']);
        $group->write();

        $create = $this->service->saveDefinition([
            'title' => 'Meta',
            'description' => 'A described workflow',
            'initialActionButtonText' => 'Submit for review',
            'remindDays' => 5,
            'groupIds' => [$group->ID],
            'steps' => [['id' => 'a', 'title' => 'Review', 'type' => 'approval', 'branches' => []]],
        ]);

        $definition = WorkflowDefinition::get()->byID($create['id']);
        $this->assertSame('A described workflow', $definition->Description);
        $this->assertSame('Submit for review', $definition->InitialActionButtonText);
        $this->assertSame(5, (int)$definition->RemindDays);
        $this->assertTrue($definition->Groups()->filter('ID', $group->ID)->exists(), 'definition group assigned');
    }

    public function testSaveDefinitionWithEmptyTitle(): void
    {
        $result = $this->service->saveDefinition([
            'steps' => [['id' => 'x', 'title' => 'Step', 'type' => 'approval', 'branches' => []]],
        ]);

        $this->assertSame('Untitled Workflow', WorkflowDefinition::get()->byID($result['id'])->Title);
    }

    public function testSaveDefinitionWithNoSteps(): void
    {
        $result = $this->service->saveDefinition(['title' => 'Empty', 'steps' => []]);

        $this->assertSame('ok', $result['status']);
        $this->assertCount(0, WorkflowDefinition::get()->byID($result['id'])->Actions());
    }

    public function testSaveDefinitionUpdatesExistingTitle(): void
    {
        $create = $this->service->saveDefinition([
            'title' => 'Original',
            'steps' => [['id' => 'a', 'title' => 'Review', 'type' => 'approval', 'branches' => []]],
        ]);

        $this->service->saveDefinition([
            'id' => $create['id'],
            'title' => 'Updated Workflow',
            'steps' => [['id' => 'a', 'title' => 'Review', 'type' => 'approval', 'branches' => []]],
        ]);

        $this->assertSame('Updated Workflow', WorkflowDefinition::get()->byID($create['id'])->Title);
    }

    public function testSaveDefinitionIgnoresInvalidBranchTarget(): void
    {
        $result = $this->service->saveDefinition([
            'title' => 'Bad Branch',
            'steps' => [[
                'id' => 'a',
                'title' => 'Step',
                'type' => 'approval',
                'branches' => ['approve' => ['nextStepId' => 'nonexistent']],
            ]],
        ]);

        $action = WorkflowDefinition::get()->byID($result['id'])->Actions()->first();
        $this->assertCount(0, $action->Transitions());
    }

    public function testLoadDefinitionReturnsNull(): void
    {
        $this->assertNull($this->service->loadDefinition(99999));
    }

    public function testLoadDefinitionReturnsTypedSteps(): void
    {
        $create = $this->service->saveDefinition([
            'title' => 'Loadable',
            'steps' => [
                [
                    'id' => 'a',
                    'title' => 'Review',
                    'type' => 'approval',
                    'branches' => ['approve' => ['nextStepId' => 'b']],
                ],
                ['id' => 'b', 'title' => 'Publish', 'type' => 'publish', 'branches' => []],
            ],
        ]);

        $loaded = $this->service->loadDefinition($create['id']);

        $this->assertSame('Loadable', $loaded['title']);
        $this->assertCount(2, $loaded['steps']);
        $this->assertSame('approval', $loaded['steps'][0]['type']);
        $this->assertSame('publish', $loaded['steps'][1]['type']);
        $this->assertArrayHasKey('approve', $loaded['steps'][0]['branches']);
    }
}
