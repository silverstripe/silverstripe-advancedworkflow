<?php

namespace Symbiote\AdvancedWorkflow\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowActionInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowTransition;

/**
 * Tests for the workflow engine.
 *
 * @author     marcus@symbiote.com.au
 * @license    BSD License (http://silverstripe.org/bsd-license/)
 * @package    advancedworkflow
 * @subpackage tests
 */
class WorkflowInstanceTest extends SapphireTest
{
    /**
     * @var string
     */
    protected static $fixture_file = 'useractioninstancehistory.yml';

    /**
     * @var Member
     */
    protected $currentMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currentMember = $this->objFromFixture(Member::class, 'ApproverMember01');
    }

    /**
     * Tests WorkflowInstance#getMostRecentActionForUser()
     */
    public function testGetMostRecentActionForUser()
    {
        // Single, AssignUsersToWorkflowAction in "History"
        $history01 = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance01');
        $mostRecentActionForUser01 = $history01->getMostRecentActionForUser($this->currentMember);
        $this->assertInstanceOf(
            WorkflowActionInstance::class,
            $mostRecentActionForUser01,
            'Asserts the correct ClassName is retured #1'
        );
        $this->assertEquals(
            'Assign',
            $mostRecentActionForUser01->BaseAction()->Title,
            'Asserts the correct BaseAction is retured #1'
        );

        // No AssignUsersToWorkflowAction found with Member's related Group in "History"
        $history02 = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance02');
        $mostRecentActionForUser02 = $history02->getMostRecentActionForUser($this->currentMember);
        $this->assertFalse(
            $mostRecentActionForUser02,
            'Asserts false is returned because no WorkflowActionInstance was found'
        );

        // Multiple AssignUsersToWorkflowAction in "History", only one with Group relations
        $history03 = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance03');
        $mostRecentActionForUser03 = $history03->getMostRecentActionForUser($this->currentMember);
        $this->assertInstanceOf(
            WorkflowActionInstance::class,
            $mostRecentActionForUser03,
            'Asserts the correct ClassName is retured #2'
        );
        $this->assertEquals(
            'Assign',
            $mostRecentActionForUser03->BaseAction()->Title,
            'Asserts the correct BaseAction is retured #2'
        );

        // Multiple AssignUsersToWorkflowAction in "History", both with Group relations
        $history04 = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance04');
        $mostRecentActionForUser04 = $history04->getMostRecentActionForUser($this->currentMember);
        $this->assertInstanceOf(
            WorkflowActionInstance::class,
            $mostRecentActionForUser04,
            'Asserts the correct ClassName is retured #3'
        );
        $this->assertEquals(
            'Assigned Again',
            $mostRecentActionForUser04->BaseAction()->Title,
            'Asserts the correct BaseAction is retured #3'
        );

        // Multiple AssignUsersToWorkflowAction in "History", one with Group relations
        $history05 = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance05');
        $mostRecentActionForUser05 = $history05->getMostRecentActionForUser($this->currentMember);
        $this->assertInstanceOf(
            WorkflowActionInstance::class,
            $mostRecentActionForUser05,
            'Asserts the correct ClassName is retured #4'
        );
        $this->assertEquals(
            'Assigned Me',
            $mostRecentActionForUser05->BaseAction()->Title,
            'Asserts the correct BaseAction is retured #4'
        );
    }

    /**
     * Tests WorkflowInstance#canView()
     */
    public function testCanView()
    {
        // Single, AssignUsersToWorkflowAction in "History"
        $history01 = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance01');
        $this->assertTrue($history01->canView($this->currentMember));

        // No AssignUsersToWorkflowAction found with Member's related Group in "History"
        $history02 = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance02');
        $this->assertFalse($history02->canView($this->currentMember));

        // Multiple AssignUsersToWorkflowAction in "History", only one with Group relations
        $history03 = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance03');
        $this->assertTrue($history03->canView($this->currentMember));

        // Multiple AssignUsersToWorkflowAction in "History", both with Group relations
        $history04 = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance04');
        $this->assertTrue($history04->canView($this->currentMember));

        // Multiple AssignUsersToWorkflowAction in "History"
        $history05 = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance05');
        $this->assertTrue($history05->canView($this->currentMember));
    }

    public function testValidTransitions()
    {
        $instance = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance06');
        $transition1 = $this->objFromFixture(WorkflowTransition::class, 'Transition05');
        $transition2 = $this->objFromFixture(WorkflowTransition::class, 'Transition06');
        $member1 = $this->objFromFixture(Member::class, 'Transition05Member');
        $member2 = $this->objFromFixture(Member::class, 'Transition06Member');

        // Given logged in as admin, check that there are two actions
        $this->logInWithPermission('ADMIN');
        $transitions = $instance->validTransitions()->column('ID');
        $this->assertContains($transition1->ID, $transitions);
        $this->assertContains($transition2->ID, $transitions);

        // Logged in as a member with permission on one transition, check that only this one is present
        Security::setCurrentUser($member1);
        $transitions = $instance->validTransitions()->column('ID');
        $this->assertContains($transition1->ID, $transitions);
        $this->assertNotContains($transition2->ID, $transitions);

        // Logged in as a member with permissions via group
        Security::setCurrentUser($member2);
        $transitions = $instance->validTransitions()->column('ID');
        $this->assertNotContains($transition1->ID, $transitions);
        $this->assertContains($transition2->ID, $transitions);
    }

    /**
     * Check that an assigned member can edit a page in workflow when there is no current controller, as in a task
     * running as that member
     */
    public function testCanEditTargetWithoutController(): void
    {
        $instance = $this->objFromFixture(WorkflowInstance::class, 'WorkflowInstance07');
        $target = $this->objFromFixture(SiteTree::class, 'WorkflowTarget01');
        $member = $this->objFromFixture(Member::class, 'Transition06Member');
        $instance->TargetID = $target->ID;
        $instance->write();

        $canEdit = false;
        $this->withoutController(function () use ($target, $member, &$canEdit) {
            $canEdit = Member::actAs($member, fn () => $target->canEdit());
        });

        $this->assertTrue($canEdit);
    }

    /**
     * Run a callback with the controller stack empty, then restore it
     */
    private function withoutController(callable $callback): void
    {
        $controllers = [];
        while ($controller = Controller::curr()) {
            $controller->popCurrent();
            $controllers[] = $controller;
        }
        try {
            $callback();
        } finally {
            foreach (array_reverse($controllers) as $controller) {
                $controller->pushCurrent();
            }
        }
    }
}
