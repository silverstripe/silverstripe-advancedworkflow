---
title: Adding Workflows to Other Content Objects
summary: Applying workflows to custom models and their corresponding ModelAdmins
icon: project-diagram
---

# Adding workflows to other content objects

In order to apply workflow to other classes (e.g. `MyObject`), you need
to apply it to both the model class and the controller
which is used for editing it. Here's an example for `MyObject`
which is managed through a `MyObjectAdmin` controller,
extending from [`ModelAdmin`](api:SilverStripe\Admin\ModelAdmin).

```yml
App\Models\MyObject:
  extensions:
    - 'Symbiote\AdvancedWorkflow\Extensions\WorkflowApplicable'
App\ModelAdmins\MyObjectAdmin:
  extensions:
    - 'Symbiote\AdvancedWorkflow\Extensions\AdvancedWorkflowExtension'
```

Notification emails sent by a [`NotifyUsersWorkflowAction`](api:Symbiote\AdvancedWorkflow\Actions\NotifyUsersWorkflowAction) substitute a known set of variables and never evaluate their content as a template. See the [Security](./04_security.md) section for more details.
