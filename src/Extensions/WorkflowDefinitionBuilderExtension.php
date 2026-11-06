<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;

/**
 * Extension on WorkflowDefinition that injects the visual
 * workflow builder container into the edit form.
 */
class WorkflowDefinitionBuilderExtension extends Extension
{
    public function updateCMSFields(FieldList $fields): void
    {
        $owner = $this->getOwner();

        if (!$owner->exists()) {
            return;
        }

        $html = sprintf(
            '<div id="workflow-builder-root" data-definition-id="%d"></div>',
            $owner->ID
        );

        $fields->addFieldToTab(
            'Root.VisualBuilder',
            LiteralField::create('WorkflowBuilderPanel', $html)
        );
    }
}
