---
title: Workflow Status Panel and Visual Builder
summary: Show workflow progress and actions in a panel on the page edit form, and edit workflow definitions visually
icon: stream
---

# Workflow status panel and visual builder

## Visual workflow builder

Every workflow definition has a "Visual builder" tab. It shows the definition as a series of review
stages, and lets you add, remove and reorder stages, choose who is assigned to them, and edit each
step's settings. Saving the builder updates the same actions and transitions that the "Main" tab
lists, so both views can be used side by side.

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
- A page can be published once its most recent workflow has completed without being rejected,
  as long as the page has not been edited since. Editing the page again requires a new approval.

The panel's buttons submit the edit form, so unsaved changes are saved before a workflow starts or
moves to its next step.
