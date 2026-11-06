<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Extensions;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\TabSet;
use Symbiote\AdvancedWorkflow\Extensions\WorkflowUIExtension;

class WorkflowUIExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * Regression: the class name was escaped with addslashes(), which doubles the
     * backslashes in a namespaced class. The panel then asked the API about a
     * class that does not exist and rendered "Unable to load workflow status."
     */
    public function testPanelRootPreservesNamespacedClassName(): void
    {
        $field = $this->panelField($this->makePage());

        $this->assertNotNull($field);
        $this->assertStringContainsString(
            'data-class-name="' . SiteTree::class . '"',
            (string)$field->getContent()
        );
        $this->assertStringNotContainsString('\\\\', (string)$field->getContent());
    }

    public function testPanelRootCarriesRecordId(): void
    {
        $page = $this->makePage();

        $field = $this->panelField($page);

        $this->assertNotNull($field);
        $this->assertStringContainsString('data-record-id="' . $page->ID . '"', (string)$field->getContent());
    }

    public function testNoPanelRootForUnsavedRecord(): void
    {
        $this->assertNull($this->panelField(SiteTree::create(['Title' => 'Unsaved'])));
    }

    private function makePage(): SiteTree
    {
        $page = SiteTree::create(['Title' => 'Workflow panel test']);
        $page->write();

        return $page;
    }

    private function panelField(SiteTree $page): ?\SilverStripe\Forms\FormField
    {
        $form = Form::create(null, 'EditForm', FieldList::create(TabSet::create('Root')), FieldList::create());
        $form->loadDataFrom($page);

        (new WorkflowUIExtension())->updateEditForm($form);

        return $form->Fields()->fieldByName('WorkflowPanel');
    }
}
