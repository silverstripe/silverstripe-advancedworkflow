---
title: Workflow Status Panel and Visual Builder
summary: Show workflow progress and actions in a panel on the page edit form, and edit workflow definitions visually
icon: stream
---

# Workflow status panel and visual builder

## Visual workflow builder

Every workflow definition has a "Visual builder" tab. It shows the definition as a series of review
stages, and lets you add and remove stages, choose who is assigned to them, and edit each step's
settings. Saving the builder updates the same actions and transitions that the "Main" tab lists, so
both views can be used side by side.

Saving keeps each action's class unless its step type was changed in the builder, so plain
`WorkflowAction` records and project-specific action subclasses keep their own logic and data.

The builder needs the "Create workflow" permission (`CREATE_WORKFLOW`).

## Workflow status panel

The status panel sits at the top of the page edit form. It shows which stage a workflow is at,
who it is assigned to and its recent activity, and holds the buttons to start, approve or reject it.

The panel is disabled by default. Enable it in your project's configuration:

```yml
Symbiote\AdvancedWorkflow\Services\WorkflowService:
  enable_workflow_panel: true
```

When the panel is enabled:

- The workflow buttons in the action bar of the page edit form are replaced by the panel's buttons.
  Records edited anywhere else, such as in a GridField or a `ModelAdmin`, keep the action bar buttons.
- A page's own publish permissions apply once its most recent workflow has been approved. A workflow
  cannot grant publishing to a user who could not otherwise publish the page.
- The approval only covers the content it approved. Editing the page, or anything it owns such as
  elemental blocks or files, requires a new approval.

The panel's buttons submit the edit form, so unsaved changes are saved before a workflow starts or
moves to its next step.

Workflow history, comments and assignees are only included for users who can view the workflow
instance (`WorkflowInstance::canView()`). Other users who can view the record only see its status.

## Workflow outcomes

When a workflow completes, `WorkflowApprovalService` records on the `WorkflowInstance`:

- `Outcome`: a `WorkflowOutcome` value. A workflow is `Rejected` when it ran a `CancelWorkflowAction`,
  and `Approved` otherwise.
- `ContentSignature`: for approved workflows, a hash of the draft versions of the record and
  everything it owns, recursively (`findOwned()`).

The publish check compares the stored signature with the record's current one. Workflows that
completed before these fields existed have no recorded outcome, so they do not count as approved.

## Endpoints

`WorkflowBuilderController` is an `AdminController` at `admin/workflow-ui`. Its endpoint URLs reach
the client through the admin client config (`getClientConfig()`), so they follow the site's base
URL and admin route. Saving a workflow requires a POST with the CMS security token.
