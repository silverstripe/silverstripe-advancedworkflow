<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Group;
use Symbiote\AdvancedWorkflow\Actions\AssignUsersToWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\CancelWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\NotifyUsersWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\SimpleApprovalWorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowTransition;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Creates example workflow definitions for demonstration and testing.
 *
 * Run via CLI:
 *   vendor/bin/sake dev/tasks/CreateExampleWorkflowTask
 *
 * Or via browser:
 *   /dev/tasks/CreateExampleWorkflowTask
 *
 * This creates a "Two-Stage Editorial Review" workflow that demonstrates
 * a more complex multi-step approval process with two approval gates.
 */
class CreateExampleWorkflowTask extends BuildTask
{
    protected string $title = 'Create Example Workflow Definitions';

    protected static string $description = 'Creates example workflow definitions including a complex two-stage editorial review workflow';

    protected static string $commandName = 'CreateExampleWorkflowTask';

    private const DEFINITION_TITLE = 'Two-Stage Editorial Review';

    /**
     * Workflow step configuration.
     * Each entry defines a step name, its action class, sort order, and properties.
     */
    private const STEPS = [
        [
            'title' => 'Assign to Content Reviewers',
            'class' => AssignUsersToWorkflowAction::class,
            'sort' => 1,
        ],
        [
            'title' => 'Notify Content Reviewers',
            'class' => NotifyUsersWorkflowAction::class,
            'sort' => 2,
            'properties' => [
                'EmailSubject' => 'Content review requested: $Title',
                'EmailFrom' => '',
                'EmailTemplate' => 'A content review has been requested for "$Title" by $Initiator.Name. Please log in to the CMS to review the changes.',
            ],
        ],
        [
            'title' => 'Content Review',
            'class' => SimpleApprovalWorkflowAction::class,
            'sort' => 3,
            'properties' => [
                'AllowCommenting' => true,
                'AllowEditing' => 'By Assignees',
            ],
        ],
        [
            'title' => 'Assign to Senior Editors',
            'class' => AssignUsersToWorkflowAction::class,
            'sort' => 4,
        ],
        [
            'title' => 'Notify Senior Editors',
            'class' => NotifyUsersWorkflowAction::class,
            'sort' => 5,
            'properties' => [
                'EmailSubject' => 'Editorial sign-off needed: $Title',
                'EmailFrom' => '',
                'EmailTemplate' => '"$Title" has passed content review and needs your final sign-off. Please log in to the CMS to approve or reject.',
            ],
        ],
        [
            'title' => 'Editorial Sign-off',
            'class' => SimpleApprovalWorkflowAction::class,
            'sort' => 6,
            'properties' => [
                'AllowCommenting' => true,
                'AllowEditing' => 'No',
            ],
        ],
        [
            'title' => 'Assign Initiator (Approved)',
            'class' => AssignUsersToWorkflowAction::class,
            'sort' => 7,
        ],
        [
            'title' => 'Notify Initiator (Approved)',
            'class' => NotifyUsersWorkflowAction::class,
            'sort' => 8,
            'properties' => [
                'EmailSubject' => 'Content approved: $Title',
                'EmailFrom' => '',
                'EmailTemplate' => 'Your content "$Title" has been approved by editorial review. You may now publish the page when ready.',
            ],
        ],
        [
            'title' => 'Reject Changes',
            'class' => CancelWorkflowAction::class,
            'sort' => 9,
        ],
        [
            'title' => 'Assign Initiator (Rejected)',
            'class' => AssignUsersToWorkflowAction::class,
            'sort' => 10,
        ],
        [
            'title' => 'Notify Initiator (Rejected)',
            'class' => NotifyUsersWorkflowAction::class,
            'sort' => 11,
            'properties' => [
                'EmailSubject' => 'Content changes rejected: $Title',
                'EmailFrom' => '',
                'EmailTemplate' => 'Your content "$Title" has been rejected. Please review the feedback and resubmit when ready.',
            ],
        ],
    ];

    /**
     * Transition wiring: [sourceStep => [[transitionTitle, targetStep], ...]]
     * Step names must match the 'title' values in STEPS.
     */
    private const TRANSITIONS = [
        'Assign to Content Reviewers' => [
            ['Notify', 'Notify Content Reviewers'],
        ],
        'Notify Content Reviewers' => [
            ['Review', 'Content Review'],
        ],
        'Content Review' => [
            ['Approve', 'Assign to Senior Editors'],
            ['Reject', 'Reject Changes'],
        ],
        'Assign to Senior Editors' => [
            ['Notify', 'Notify Senior Editors'],
        ],
        'Notify Senior Editors' => [
            ['Sign-off', 'Editorial Sign-off'],
        ],
        'Editorial Sign-off' => [
            ['Approve', 'Assign Initiator (Approved)'],
            ['Reject', 'Reject Changes'],
        ],
        'Assign Initiator (Approved)' => [
            ['Notify', 'Notify Initiator (Approved)'],
        ],
        'Reject Changes' => [
            ['Assign', 'Assign Initiator (Rejected)'],
        ],
        'Assign Initiator (Rejected)' => [
            ['Notify', 'Notify Initiator (Rejected)'],
        ],
    ];

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $output->writeln('Creating example workflow definitions...');
        $output->writeln('');

        $result = $this->createTwoStageWorkflow($output);

        if ($result === Command::FAILURE) {
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('Done. You can now assign this workflow to pages via Settings → Workflow.');

        return Command::SUCCESS;
    }

    /**
     * Creates the Two-Stage Editorial Review workflow definition with all actions and transitions.
     */
    private function createTwoStageWorkflow(PolyOutput $output): int
    {
        $existing = WorkflowDefinition::get()->filter('Title', self::DEFINITION_TITLE)->first();

        if ($existing instanceof WorkflowDefinition && $existing->exists()) {
            $output->writeln(sprintf('Removing existing "%s" workflow (ID: %d)...', self::DEFINITION_TITLE, $existing->ID));
            $this->removeDefinition($existing);
        }

        $definition = WorkflowDefinition::create();
        $definition->Title = self::DEFINITION_TITLE;
        $definition->Description = 'A two-stage editorial workflow: content is first reviewed by Content Reviewers, '
            . 'then requires sign-off from Senior Editors before publishing. '
            . 'Either stage can reject back to the author with feedback.';
        $definition->RemindDays = 3;
        $definition->write();

        $output->writeln(sprintf('Created workflow definition "%s" (ID: %d)', self::DEFINITION_TITLE, $definition->ID));

        $this->attachGroups($definition, $output);

        $actions = $this->createActions($definition, $output);

        $transitionCount = $this->createTransitions($actions, $output);

        $output->writeln(sprintf(
            'Created %d actions and %d transitions',
            count($actions),
            $transitionCount
        ));

        return Command::SUCCESS;
    }

    /**
     * Attaches security groups to the workflow definition if they exist.
     */
    private function attachGroups(WorkflowDefinition $definition, PolyOutput $output): void
    {
        $contentAuthors = Group::get()->filter('Code', 'content-authors')->first();

        if ($contentAuthors instanceof Group && $contentAuthors->exists()) {
            $definition->Groups()->add($contentAuthors);
            $output->writeln(sprintf('  Attached group: %s', $contentAuthors->Title));
        }

        $administrators = Group::get()->filter('Code', 'administrators')->first();

        if ($administrators instanceof Group && $administrators->exists()) {
            $definition->Groups()->add($administrators);
            $output->writeln(sprintf('  Attached group: %s', $administrators->Title));
        }
    }

    /**
     * Creates all workflow actions from the STEPS constant.
     *
     * @return array<string, WorkflowAction> Map of step title to action object
     */
    private function createActions(WorkflowDefinition $definition, PolyOutput $output): array
    {
        $actions = [];

        foreach (self::STEPS as $stepConfig) {
            $className = $stepConfig['class'];

            /** @var WorkflowAction $action */
            $action = $className::create();
            $action->Title = $stepConfig['title'];
            $action->Sort = $stepConfig['sort'];
            $action->WorkflowDefID = $definition->ID;

            if (isset($stepConfig['properties'])) {
                foreach ($stepConfig['properties'] as $property => $value) {
                    $action->$property = $value;
                }
            }

            $action->write();
            $actions[$stepConfig['title']] = $action;

            $output->writeln(sprintf('  Step %d: %s (%s)', $stepConfig['sort'], $stepConfig['title'], $this->shortClassName($className)));
        }

        return $actions;
    }

    /**
     * Creates all transitions between actions from the TRANSITIONS constant.
     *
     * @param array<string, WorkflowAction> $actions Map of step title to action object
     */
    private function createTransitions(array $actions, PolyOutput $output): int
    {
        $count = 0;

        foreach (self::TRANSITIONS as $sourceName => $transitionList) {
            if (!isset($actions[$sourceName])) {
                $output->writeln(sprintf('  WARNING: Source action "%s" not found, skipping transitions', $sourceName));
                continue;
            }

            $sourceAction = $actions[$sourceName];

            foreach ($transitionList as [$transitionTitle, $targetName]) {
                if (!isset($actions[$targetName])) {
                    $output->writeln(sprintf('  WARNING: Target action "%s" not found for transition "%s"', $targetName, $transitionTitle));
                    continue;
                }

                $targetAction = $actions[$targetName];

                $transition = WorkflowTransition::create();
                $transition->Title = $transitionTitle;
                $transition->ActionID = $sourceAction->ID;
                $transition->NextActionID = $targetAction->ID;
                $transition->write();

                $count++;
            }
        }

        return $count;
    }

    /**
     * Removes a workflow definition and all its related actions and transitions.
     */
    private function removeDefinition(WorkflowDefinition $definition): void
    {
        foreach ($definition->Actions() as $action) {
            foreach ($action->Transitions() as $transition) {
                $transition->delete();
            }

            $action->delete();
        }

        $definition->delete();
    }

    /**
     * Returns the short class name without namespace.
     */
    private function shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
