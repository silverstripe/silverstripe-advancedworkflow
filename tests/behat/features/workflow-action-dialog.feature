@javascript
Feature: Edit a workflow action
  As a cms administrator
  I want to edit a workflow action in its dialog
  So that I can change a workflow without leaving it

  Background:
    Given a workflow "Two-Step" using the "Review and Approve" template
    And I am logged in with "ADMIN" permissions
    And I go to "/admin/workflows"
    And I click "Two-Step" in the ".ss-gridfield-items" element
    And I wait until I see the ".workflow-field" element

  Scenario: Saving a workflow action closes its dialog and updates the workflow
    When I click "Edit" in the ".workflow-field-action" element
    And I wait until I see the ".workflow-field-dialog form" element
    And I fill in "Title" with "Apply now" in the ".workflow-field-dialog" region
    And I click "Save" in the ".workflow-field-dialog" element
    And I wait until I see the text "Apply now"
    Then I should not see the ".workflow-field-dialog form" element
    And I should see the ".cms-container" element
    And I should see "Apply now" in the ".workflow-field" region
