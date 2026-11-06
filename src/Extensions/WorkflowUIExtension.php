<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Extensions;

use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;

/**
 * Extension on CMSMain that injects the workflow status panel
 * container into the page edit form.
 */
class WorkflowUIExtension extends Extension
{
    public function updateEditForm(Form $form): void
    {
        $record = $form->getRecord();

        if (!$record instanceof DataObject || !$record->exists()) {
            return;
        }

        // raw2att (not addslashes) — namespaced class names contain backslashes,
        // and slash-escaping them produces a class name the API cannot resolve.
        $html = sprintf(
            '<div id="workflow-panel-root"
                data-record-id="%d"
                data-class-name="%s"></div>',
            (int)$record->ID,
            Convert::raw2att((string)$record->ClassName)
        );

        $form->Fields()->insertBefore(
            'Root',
            LiteralField::create('WorkflowPanel', $html)
        );
    }
}
