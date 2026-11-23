---
title: Setting up a simple CMS workflow
---
# Setting up a simple CMS workflow

Since workflows can vary greatly across websites, there's no default workflow.

You will need to configure the workflow in the *Workflows* admin interface. Here, we'll create a simple ‘Review and
Approve’ workflow with the following features:

- 'Content Authors' make a change and request approval from 'Content Publishers'.
- An email is sent to the Content Publishers for approval.
- Content Publishers can view and then reject or approve the change.
- An email is then sent to Content Author to notify them of the rejection or that the content is to be published.

![Flow diagram of advanced workflow objective](_images/advancedworkflow-review-and-approve.jpg)

> [!TIP]
> You can also build a workflow like this one as a series of review stages in the *Visual builder* tab of a workflow.
> See [building workflows with the visual builder](workflow-visual-builder.md).

## Step 1. Set up user groups

CMS administrators must first set up user groups to be configured in the Workflow. See
[Changing and managing users](https://userhelp.silverstripe.org/en/managing_your_website/changing_and_managing_users/)
for setting up users and groups in the *Security* left-hand navigation menu item.

For this example workflow set up the following user groups:

1. Content Authors - The users creating and maintaining content on the website. These users will change content and apply for approval.
1. Content Publishers - The users who will review and approve any content changes on the website before they are published to the live web site.

You must ensure the above user groups have the following *Advanced Workflow* permissions checked:

![Workflow permissions](_images/advancedworkflow-workflow-permissions.png)

You must also ensure the above user groups have the following *CMS Access* permissions checked:

![CMS access permissions](_images/advancedworkflow-cms-permissions.png)

Once your user groups are set, you can apply Workflow actions to these user groups.

See [more on workflow permissions.](workflow-permissions.md)

> [!NOTE]
> Any user with "full administrative rights" set will see all Workflow
> action buttons in the CMS, plus they will have the ability to draft and publish content regardless of whether or not a
> workflow is configured on a page.

## Step 2. Create a workflow definition

There is no default workflow in Silverstripe CMS since actual workflows vary greatly across web sites and hence one
must be created.

### Using a workflow template (optional)

Workflow provides a simple "Review and Approve" template that can be selected when setting up a workflow definition.
This is a 2 step workflow (one person authors content and another approves and publishes it) that you can use as a
starting point for more complex workflows.
Once you have added a template, you will need to configure it with your custom settings.

![Workflow create](_images/advancedworkflow-create.png)

![Workflow actions](_images/advancedworkflow-actions.png)

## Step 3. Assigning users to the workflow

Now, the workflow must be assigned to users in two areas.

The first is the workflow process itself, that is, which users will be restricted to the workflow.

This is set via the workflow definition ‘Restrict to Users’ or ‘Restrict to Groups’ fields. In this example, we apply
the workflow restrictions to Content Authors who wish to make content changes. Select'Content Authors' from the drop
down.

![Restrict to users](_images/advancedworkflow-restrictworkflow.png)

Next, you must assign the correct users to each action. For example, when a Content Author triggers the workflow, it is
the Content Publishers who must then receive and action the next step in the workflow process.

First select the “Edit” button on the "Apply for approval" action.

For this workflow action, select 'Content Publishers' from the "Groups" dropdown menu. These users will now receive the
email notifications of content changes in the workflow, once the “Apply for approval” button is clicked by the Content
Authors.

Next, select the "Notify users" action and fill out the "Notification Email". You can use several placeholders to
create your email template. Set the "from email", "address", and "subject" fields. See the "Formatting Help" drop down
for help.

![Notify users](_images/advancedworkflow-notify-users.png)

Repeat these steps for the other actions  - "Assign Initiator Publish", "Notify Initiator Publish", "Assign Initiator
Cancel", and "Notify Initiator Cancel" - but this time setting the user to the "Initiator".

![Assign initiator](_images/advancedworkflow-assign-initiator.png)

![Email notification](_images/advancedworkflow-email-notification.png)

> [!NOTE]
> You can further restrict certain actions and transitions to specific users,
> if desired, using the “Users” check boxes and the “Restricted users” tabs on transition forms.

## Step 4. Assign workflows to a page

To make use of workflows you need to assign a workflow definition to your content pages.

To do this:

1. Select the page you wish a workflow to be applied to
1. Select the *Settings* tab
1. Select the *Workflow* tab
1. Select the desired workflow from the "Applied Workflow" drop down
1. Save your page

Your workflow has now been associated with your page (or section of pages). If you select the *Content* tab, you should
notice a new button available labelled as per your first workflow action. For example, this may read "Apply for
approval".

> [!NOTE]
> The default workflow selection is "Inherit from parent" so you can
> assign a workflow to a parent page and each child page will use that workflow without any further configuration.

![Apply workflow](_images/advancedworkflow-apply-workflow.png)

## Testing and using your new workflow

1. Logout of the CMS and log back in again as your 'Content Author' user.
1. Navigate to the page you created in the previous step, enter some text into the "Content" area,select the "Apply for approval" button, now depending on users and groups assigned to your transitions and content-permissions.
1. Following this documentation's example, the page should now no longer be editable after refreshing. It will remain so until it has progressed through the workflow instance to the final Content Publisher's "Publish item" action.

![Readonly workflow](_images/advancedworkflow-readonly.png)

Notice that if you select the "Workflows" left-hand navigation menu item once again, you should see a list of your "Submitted items".
At this point this should be showing a single entry for the "Workflow Test" page. You can refer back to this list and observe the "Current action" column
update as your changes progress through the workflow.

Depending on how you configured the email address for the Editor user you created in the "Security" left-hand navigation menu item before starting, an email should have been received
in the Editor's email inbox alerting them that some new content is available for review/approval by them.

Now logout of the CMS and log back in again as a user from the "Editors" group. Select the "Workflows" left-hand navigation menu item, and notice that you have
a similar list to before, but this one is entitled "Your pending items". These are the workflow changes automatically assigned to you that are now awaiting your
attention.

Click anywhere on the table row and in the "Next Action" drop-down menu, make your choice as Editor, then select "Save". You can also see a log of the actions.

> [!NOTE]
> You are also able to select the blue content-title text to view the page
> itself within the CMS and manually review the changes before committing to accepting or rejecting them. If you now go
> back to the *Workflow* left-hand navigation menu item, you'll notice that the items that were in the "Your Pending Items" list, are no longer
> there. This is because the action is now with the users of the "Content Publishers" group.

Logout of the CMS, and login as your "Content Publisher" user. Select the "Workflows" left-hand navigation menu item,
and notice that there is a similar list to before, entitled "Your pending items", again with the "Workflow Test" page being the only item. Click on this item and make your selection as manager, and then select
the "Save" button.

If you were to go and check the edit screen for this page, you'll notice now, having gone through the full workflow,
that the action button at the bottom shows "Apply for approval" once again. Logging back-in as a 'Content Author', this
user is now able to make further changes.

## Adding workflow actions (optional)

If you want to modify the above sample workflow or create a workflow without using the available template, here is a brief description of what every action does.

Workflow Actions are ‘triggers’ for the next process in a workflow such as notifications or publishing content.

Before you begin, you have to save your workflow definition. Then, create all your actions to match your desired workflow process and then create the transitions from each action.

From the ‘Create an action” drop down, select the following actions:

1. **Assign Users To Workflow Action**: Title this “Apply for Approval”.
This creates a Workflow Button on a page, which then assigns the workflow process to configured users.
1. **Notify Users Workflow Action**: Title this “Notify Publishers”. This action configures the notification emails sent when an action is triggered.
1. **Simple Approval Workflow Action**: Title this “Approval”. This provides the decision point and two buttons to Publish or Reject the current content change.
1. **Publish Item Workflow Action:** Title this “Publish”. This provides a button to register approval to proceed with publishing the content.
1. **Assign Users To Workflow Action**: Title this “Assign Initiator Publish”. This action selects the Content Authors to be notified by email that their content is to be published.
1. **Notify Users Workflow Action:** Title this “Notify Initiator Publish”. This action emails the above “initiator” to Publish the content they have changed.
1. **Cancel Workflow Action**: Tile this “Reject Changes”. This provides a button to register rejection of changes.
1. **Assign Users To Workflow Action**: Title this “Assign Initiator Cancel”. This actions selects the Content Authors who are to Reject the changes.
1. **Notify Users Workflow Action**: Title this “Notify Initiator Cancel”. This actions emails the above “initiator” to not publish what is changed.

Next, add your transitions as in the below example, by clicking “Add Transition” on each action, setting a title and a "Next Action":

![Flow diagram of advanced workflow objective](_images/advancedworkflow-transitions.jpg)

As you can see in the above workflow, the “Approval” action is the decision point at which one of the two transitions to either Publish or Reject actions is selected.

Once either action button is pressed, the workflow is assigned back to the original initiator, and an email  notification is triggered (email is configured in the “Notify Initiator Cancel” or “Notify Initiator Publish” action).
