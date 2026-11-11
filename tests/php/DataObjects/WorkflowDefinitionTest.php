<?php

namespace Symbiote\AdvancedWorkflow\Tests\DataObjects;

use SilverStripe\Control\Controller;
use SilverStripe\Dev\SapphireTest;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;

class WorkflowDefinitionTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * Check that a definition can be written with no current controller, as on the command line
     */
    public function testWriteWithoutController(): void
    {
        $definition = WorkflowDefinition::create([
            'Title' => 'Test workflow',
        ]);
        $this->withoutController(fn () => $definition->write());

        $this->assertSame(
            'Test workflow',
            WorkflowDefinition::get()->byID($definition->ID)?->Title
        );
    }

    /**
     * Check that `incomingTitle()` falls back to the default title with no current controller
     */
    public function testDefaultTitleWithoutController(): void
    {
        $definition = WorkflowDefinition::create();
        $this->withoutController(fn () => $definition->write());

        $this->assertSame(
            WorkflowDefinition::$default_workflow_title_base . ' 1',
            $definition->Title
        );
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
