<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Tasks;

use SilverStripe\Dev\SapphireTest;
use Symbiote\AdvancedWorkflow\Actions\AssignUsersToWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\CancelWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\NotifyUsersWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\SimpleApprovalWorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\Tasks\CreateExampleWorkflowTask;

/**
 * @phpcs:disable SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
 */
class CreateExampleWorkflowTaskTest extends SapphireTest
{
    /**
     * @var bool
     */
    protected $usesDatabase = true;

    private const DEFINITION_TITLE = 'Two-Stage Editorial Review';

    private const EXPECTED_ACTION_COUNT = 11;
    private const EXPECTED_TRANSITION_COUNT = 11;
    private const EXPECTED_APPROVAL_STEPS = 2;

    public function testTaskCreatesWorkflowDefinition(): void
    {
        $this->runTaskSilently();

        $definition = WorkflowDefinition::get()->filter('Title', self::DEFINITION_TITLE)->first();

        $this->assertNotNull($definition, 'Workflow definition should be created');
        $this->assertInstanceOf(WorkflowDefinition::class, $definition);
        $this->assertNotEmpty($definition->Description);
    }

    public function testTaskCreatesCorrectNumberOfActions(): void
    {
        $this->runTaskSilently();

        $definition = WorkflowDefinition::get()->filter('Title', self::DEFINITION_TITLE)->first();
        $actions = $definition->Actions();

        $this->assertCount(self::EXPECTED_ACTION_COUNT, $actions);
    }

    public function testTaskCreatesTwoApprovalSteps(): void
    {
        $this->runTaskSilently();

        $definition = WorkflowDefinition::get()->filter('Title', self::DEFINITION_TITLE)->first();
        $approvalActions = $definition->Actions()->filter('ClassName', SimpleApprovalWorkflowAction::class);

        $this->assertCount(self::EXPECTED_APPROVAL_STEPS, $approvalActions);

        $titles = $approvalActions->column('Title');
        $this->assertContains('Content Review', $titles);
        $this->assertContains('Editorial Sign-off', $titles);
    }

    public function testTaskCreatesCorrectTransitions(): void
    {
        $this->runTaskSilently();

        $definition = WorkflowDefinition::get()->filter('Title', self::DEFINITION_TITLE)->first();

        $totalTransitions = 0;
        foreach ($definition->Actions() as $action) {
            $totalTransitions += $action->Transitions()->count();
        }

        $this->assertSame(self::EXPECTED_TRANSITION_COUNT, $totalTransitions);
    }

    public function testApprovalStepsHaveTwoBranches(): void
    {
        $this->runTaskSilently();

        $definition = WorkflowDefinition::get()->filter('Title', self::DEFINITION_TITLE)->first();
        $approvalActions = $definition->Actions()->filter('ClassName', SimpleApprovalWorkflowAction::class);

        foreach ($approvalActions as $action) {
            $transitions = $action->Transitions();
            $this->assertCount(
                2,
                $transitions,
                sprintf('Approval step "%s" should have exactly 2 transitions (approve/reject)', $action->Title)
            );

            $transitionTitles = $transitions->column('Title');
            $this->assertContains('Approve', $transitionTitles);
            $this->assertContains('Reject', $transitionTitles);
        }
    }

    public function testTaskIsIdempotent(): void
    {
        $this->runTaskSilently();
        $this->runTaskSilently();

        $definitions = WorkflowDefinition::get()->filter('Title', self::DEFINITION_TITLE);

        $this->assertCount(1, $definitions, 'Running the task twice should not create duplicates');
    }

    public function testActionSortOrder(): void
    {
        $this->runTaskSilently();

        $definition = WorkflowDefinition::get()->filter('Title', self::DEFINITION_TITLE)->first();
        $actions = $definition->Actions()->sort('Sort ASC');

        $firstAction = $actions->first();
        $this->assertInstanceOf(AssignUsersToWorkflowAction::class, $firstAction);
        $this->assertSame('Assign to Content Reviewers', $firstAction->Title);

        $lastAction = $actions->last();
        $this->assertInstanceOf(NotifyUsersWorkflowAction::class, $lastAction);
    }

    public function testRejectBranchesPointToCancelAction(): void
    {
        $this->runTaskSilently();

        $definition = WorkflowDefinition::get()->filter('Title', self::DEFINITION_TITLE)->first();
        $approvalActions = $definition->Actions()->filter('ClassName', SimpleApprovalWorkflowAction::class);

        foreach ($approvalActions as $action) {
            $rejectTransition = $action->Transitions()->filter('Title', 'Reject')->first();

            $this->assertNotNull($rejectTransition, sprintf('"%s" should have a Reject transition', $action->Title));

            $nextAction = $rejectTransition->NextAction();
            $this->assertInstanceOf(
                CancelWorkflowAction::class,
                $nextAction,
                sprintf('Reject from "%s" should point to CancelWorkflowAction', $action->Title)
            );
        }
    }

    /**
     * Runs the task with suppressed output.
     */
    private function runTaskSilently(): void
    {
        $task = new CreateExampleWorkflowTask();

        $input = $this->createMock(\Symfony\Component\Console\Input\InputInterface::class);
        // PolyOutput::writeln returns void — a mock auto-stubs it; do not set an
        // explicit return (PHPUnit 11 rejects willReturn on a void method).
        $output = $this->createMock(\SilverStripe\PolyExecution\PolyOutput::class);

        $reflection = new \ReflectionMethod($task, 'execute');
        $reflection->invoke($task, $input, $output);
    }
}
