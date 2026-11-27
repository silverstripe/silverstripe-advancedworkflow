import i18n from 'i18n';
import { STEP_TYPES } from './workflowStages';

// ============================================================
// stepMeta
// Presentational metadata for each step type: the icon, the
// plain-language label/description shown to CMS users, and the
// declarative config-field editors. Kept separate from
// workflowStages (pure logic) so both the builder and StageCard
// render from one source of truth.
//
// Copy is translated on each call rather than at import, so it
// uses the dictionaries the CMS has loaded by render time.
// ============================================================

// Values must match WorkflowAction.AllowEditing enum in the database.
const allowEditingOptions = () => [
  { value: 'No', label: i18n._t('WorkflowBuilder.EDITING_NONE', 'Not editable') },
  { value: 'By Assignees', label: i18n._t('WorkflowBuilder.EDITING_ASSIGNEES', 'Editable by assignees') },
  { value: 'Content Settings', label: i18n._t('WorkflowBuilder.EDITING_CONTENT_SETTINGS', 'Use content settings') },
];

// Values must match WorkflowTransition.Type enum in the database.
export const transitionTypes = () => [
  { value: 'Active', label: i18n._t('WorkflowBuilder.TRANSITION_ACTIVE', 'Active — a user clicks to proceed') },
  { value: 'Passive', label: i18n._t('WorkflowBuilder.TRANSITION_PASSIVE', 'Passive — proceeds automatically') },
];

/**
 * Config editors for a step type. `input` drives how the field renders;
 * `source` (groups|users) pulls options from workflowMeta.
 *
 * @param {string} type One of STEP_TYPES.
 * @returns {Array<Object>|null}
 */
export const configFieldsFor = (type) => {
  const fields = {
    [STEP_TYPES.APPROVAL]: [
      {
        key: 'allowEditing',
        label: i18n._t('WorkflowBuilder.FIELD_ALLOW_EDITING', 'Content editing during this step'),
        input: 'select',
        options: allowEditingOptions(),
      },
      {
        key: 'allowCommenting',
        label: i18n._t('WorkflowBuilder.FIELD_ALLOW_COMMENTING', 'Allow reviewers to leave a comment'),
        input: 'checkbox',
      },
    ],
    [STEP_TYPES.NOTIFICATION]: [
      {
        key: 'emailSubject',
        label: i18n._t('WorkflowBuilder.FIELD_EMAIL_SUBJECT', 'Email subject'),
        input: 'text',
        help: i18n._t('WorkflowBuilder.FIELD_EMAIL_SUBJECT_HELP', 'Supports variables like $Context.Title.'),
      },
      { key: 'emailFrom', label: i18n._t('WorkflowBuilder.FIELD_EMAIL_FROM', 'From address'), input: 'text' },
      { key: 'emailTemplate', label: i18n._t('WorkflowBuilder.FIELD_EMAIL_BODY', 'Email body'), input: 'textarea' },
    ],
    [STEP_TYPES.ASSIGN]: [
      {
        key: 'assignInitiator',
        label: i18n._t('WorkflowBuilder.FIELD_ASSIGN_INITIATOR', 'Also assign the person who started the workflow'),
        input: 'checkbox',
      },
      {
        key: 'groupIds',
        label: i18n._t('WorkflowBuilder.FIELD_ASSIGN_GROUPS', 'Assign to groups'),
        input: 'multiselect',
        source: 'groups',
      },
      {
        key: 'userIds',
        label: i18n._t('WorkflowBuilder.FIELD_ASSIGN_USERS', 'Assign to specific users'),
        input: 'multiselect',
        source: 'users',
      },
    ],
    [STEP_TYPES.PUBLISH]: [
      {
        key: 'publishDelay',
        label: i18n._t('WorkflowBuilder.FIELD_PUBLISH_DELAY', 'Delay publishing (days)'),
        input: 'number',
      },
      {
        key: 'allowEmbargoedEditing',
        label: i18n._t('WorkflowBuilder.FIELD_EMBARGOED_EDITING', 'Allow editing while embargoed'),
        input: 'checkbox',
      },
    ],
    [STEP_TYPES.UNPUBLISH]: [
      {
        key: 'unpublishDelay',
        label: i18n._t('WorkflowBuilder.FIELD_UNPUBLISH_DELAY', 'Delay unpublishing (days)'),
        input: 'number',
      },
    ],
    [STEP_TYPES.SET_PROPERTY]: [
      {
        key: 'property',
        label: i18n._t('WorkflowBuilder.FIELD_PROPERTY', 'Field name to set'),
        input: 'text',
        help: i18n._t(
          'WorkflowBuilder.FIELD_PROPERTY_HELP',
          'The exact field name on the page to write to, e.g. "ReviewOutcome". '
            + 'Ask a developer if unsure — an unknown field name is silently ignored.'
        ),
      },
      {
        key: 'value',
        label: i18n._t('WorkflowBuilder.FIELD_VALUE', 'Value to store'),
        input: 'text',
        help: i18n._t('WorkflowBuilder.FIELD_VALUE_HELP', 'The value written into that field.'),
      },
    ],
    [STEP_TYPES.CANCEL]: [],
  };

  return fields[type] || null;
};

// Icon + plain-language copy per step type. `role` explains, in one
// short line, why the step exists inside a stage.
const typeMeta = () => ({
  [STEP_TYPES.APPROVAL]: {
    icon: 'approval',
    label: i18n._t('WorkflowBuilder.APPROVAL_LABEL', 'Review & approve'),
    role: i18n._t('WorkflowBuilder.APPROVAL_ROLE', 'The workflow pauses here until a reviewer approves or rejects.'),
    description: i18n._t(
      'WorkflowBuilder.APPROVAL_DESCRIPTION',
      'Someone must review and approve or reject before the workflow continues.'
    ),
  },
  [STEP_TYPES.ASSIGN]: {
    icon: 'assignment',
    label: i18n._t('WorkflowBuilder.ASSIGN_LABEL', 'Assign reviewers'),
    role: i18n._t('WorkflowBuilder.ASSIGN_ROLE', 'Decides who is responsible for the review.'),
    description: i18n._t(
      'WorkflowBuilder.ASSIGN_DESCRIPTION',
      'Assigns the workflow to the users and/or groups who handle this stage.'
    ),
  },
  [STEP_TYPES.NOTIFICATION]: {
    icon: 'notification',
    label: i18n._t('WorkflowBuilder.NOTIFICATION_LABEL', 'Notify reviewers'),
    role: i18n._t('WorkflowBuilder.NOTIFICATION_ROLE', 'Emails the assigned reviewers so they know to act.'),
    description: i18n._t('WorkflowBuilder.NOTIFICATION_DESCRIPTION', 'Sends an email to the assigned users/groups.'),
  },
  [STEP_TYPES.PUBLISH]: {
    icon: 'publish',
    label: i18n._t('WorkflowBuilder.PUBLISH_LABEL', 'Publish'),
    role: i18n._t('WorkflowBuilder.PUBLISH_ROLE', 'Publishes the content once the workflow reaches here.'),
    description: i18n._t(
      'WorkflowBuilder.PUBLISH_DESCRIPTION',
      'Publishes the content. Usually the final step after approval.'
    ),
  },
  [STEP_TYPES.CANCEL]: {
    icon: 'cancel',
    label: i18n._t('WorkflowBuilder.CANCEL_LABEL', 'Reject changes'),
    role: i18n._t('WorkflowBuilder.CANCEL_ROLE', 'Ends the workflow as rejected.'),
    description: i18n._t(
      'WorkflowBuilder.CANCEL_DESCRIPTION',
      'Ends the workflow as rejected. Reject transitions point here.'
    ),
  },
  [STEP_TYPES.SET_PROPERTY]: {
    icon: 'action',
    label: i18n._t('WorkflowBuilder.SET_PROPERTY_LABEL', 'Set a field'),
    role: i18n._t('WorkflowBuilder.SET_PROPERTY_ROLE', 'Writes a fixed value into a named field on the page.'),
    description: i18n._t(
      'WorkflowBuilder.SET_PROPERTY_DESCRIPTION',
      'Advanced (developer) step. Writes a fixed value into a named field on the page as the '
        + 'workflow passes this point — e.g. set a "ReviewOutcome" field to "Approved". It only works if a '
        + 'developer has added that field to the page.'
    ),
    advanced: true,
  },
  [STEP_TYPES.UNPUBLISH]: {
    icon: 'unpublish',
    label: i18n._t('WorkflowBuilder.UNPUBLISH_LABEL', 'Unpublish'),
    role: i18n._t('WorkflowBuilder.UNPUBLISH_ROLE', 'Takes the content off the live site.'),
    description: i18n._t(
      'WorkflowBuilder.UNPUBLISH_DESCRIPTION',
      'Advanced: takes the content off the live site (the opposite of Publish).'
    ),
    advanced: true,
  },
});

export const metaFor = (type) => {
  const meta = typeMeta();

  return meta[type] || meta[STEP_TYPES.APPROVAL];
};
