---
title: Security
summary: How notification email content is handled safely.
icon: user-shield
---

# Security

## Notification email content is not evaluated as a template

The subject, from address and body of a [`NotifyUsersWorkflowAction`](api:Symbiote\AdvancedWorkflow\Actions\NotifyUsersWorkflowAction) are edited in the CMS. These fields substitute a known set of variables, such as `$Member.FirstName`, `$Context.Title` and `$CommentHistory`, and leave everything else as literal text.

The content is never run through the template engine, so template syntax a CMS author enters stays inert text rather than executing on the server. See [adding workflows](./01_adding-workflows.md) for the full list of available variables and how to register your own.

## `whitelist_template_variables`

The [`NotifyUsersWorkflowAction`](api:Symbiote\AdvancedWorkflow\Actions\NotifyUsersWorkflowAction) workflow action has a configuration parameter, `whitelist_template_variables`, that has no effect.

The email body is not evaluated as a template, so it is always limited to the known variables regardless of this setting. Remove any `whitelist_template_variables` configuration from your project.
