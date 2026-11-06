import { STEP_TYPES } from './workflowStages';

// ============================================================
// stepMeta
// Presentational metadata for each step type: the icon, the
// plain-language label/description shown to CMS users, and the
// declarative config-field editors. Kept separate from
// workflowStages (pure logic) so both the builder and StageCard
// render from one source of truth.
// ============================================================

// Values must match WorkflowAction.AllowEditing enum in the database.
export const ALLOW_EDITING_OPTIONS = Object.freeze([
  { value: 'No', label: 'Not editable' },
  { value: 'By Assignees', label: 'Editable by assignees' },
  { value: 'Content Settings', label: 'Use content settings' },
]);

// Values must match WorkflowTransition.Type enum in the database.
export const TRANSITION_TYPES = Object.freeze([
  { value: 'Active', label: 'Active — a user clicks to proceed' },
  { value: 'Passive', label: 'Passive — proceeds automatically' },
]);

// Per-type config editors. `input` drives how the field renders;
// `source` (groups|users) pulls options from workflowMeta.
export const CONFIG_FIELDS = Object.freeze({
  [STEP_TYPES.APPROVAL]: [
    { key: 'allowEditing', label: 'Content editing during this step', input: 'select', options: ALLOW_EDITING_OPTIONS },
    { key: 'allowCommenting', label: 'Allow reviewers to leave a comment', input: 'checkbox' },
  ],
  [STEP_TYPES.NOTIFICATION]: [
    { key: 'emailSubject', label: 'Email subject', input: 'text', help: 'Supports variables like $Context.Title.' },
    { key: 'emailFrom', label: 'From address', input: 'text' },
    { key: 'emailTemplate', label: 'Email body', input: 'textarea' },
  ],
  [STEP_TYPES.ASSIGN]: [
    { key: 'assignInitiator', label: 'Also assign the person who started the workflow', input: 'checkbox' },
    { key: 'groupIds', label: 'Assign to groups', input: 'multiselect', source: 'groups' },
    { key: 'userIds', label: 'Assign to specific users', input: 'multiselect', source: 'users' },
  ],
  [STEP_TYPES.PUBLISH]: [
    { key: 'publishDelay', label: 'Delay publishing (days)', input: 'number' },
    { key: 'allowEmbargoedEditing', label: 'Allow editing while embargoed', input: 'checkbox' },
  ],
  [STEP_TYPES.UNPUBLISH]: [
    { key: 'unpublishDelay', label: 'Delay unpublishing (days)', input: 'number' },
  ],
  [STEP_TYPES.SET_PROPERTY]: [
    {
      key: 'property',
      label: 'Field name to set',
      input: 'text',
      help: 'The exact field name on the page to write to, e.g. "ReviewOutcome". '
        + 'Ask a developer if unsure — an unknown field name is silently ignored.',
    },
    { key: 'value', label: 'Value to store', input: 'text', help: 'The value written into that field.' },
  ],
  [STEP_TYPES.CANCEL]: [],
});

// Icon + plain-language copy per step type. `role` explains, in one
// short line, why the step exists inside a stage.
export const TYPE_META = Object.freeze({
  [STEP_TYPES.APPROVAL]: {
    icon: 'approval',
    label: 'Review & approve',
    role: 'The workflow pauses here until a reviewer approves or rejects.',
    description: 'Someone must review and approve or reject before the workflow continues.',
  },
  [STEP_TYPES.ASSIGN]: {
    icon: 'assignment',
    label: 'Assign reviewers',
    role: 'Decides who is responsible for the review.',
    description: 'Assigns the workflow to the users and/or groups who handle this stage.',
  },
  [STEP_TYPES.NOTIFICATION]: {
    icon: 'notification',
    label: 'Notify reviewers',
    role: 'Emails the assigned reviewers so they know to act.',
    description: 'Sends an email to the assigned users/groups.',
  },
  [STEP_TYPES.PUBLISH]: {
    icon: 'publish',
    label: 'Publish',
    role: 'Publishes the content once the workflow reaches here.',
    description: 'Publishes the content. Usually the final step after approval.',
  },
  [STEP_TYPES.CANCEL]: {
    icon: 'cancel',
    label: 'Reject changes',
    role: 'Ends the workflow as rejected.',
    description: 'Ends the workflow as rejected. Reject transitions point here.',
  },
  [STEP_TYPES.SET_PROPERTY]: {
    icon: 'action',
    label: 'Set a field',
    role: 'Writes a fixed value into a named field on the page.',
    description: 'Advanced (developer) step. Writes a fixed value into a named field on the page as the '
      + 'workflow passes this point — e.g. set a "ReviewOutcome" field to "Approved". It only works if a '
      + 'developer has added that field to the page.',
    advanced: true,
  },
  [STEP_TYPES.UNPUBLISH]: {
    icon: 'unpublish',
    label: 'Unpublish',
    role: 'Takes the content off the live site.',
    description: 'Advanced: takes the content off the live site (the opposite of Publish).',
    advanced: true,
  },
});

export const metaFor = (type) => TYPE_META[type] || TYPE_META[STEP_TYPES.APPROVAL];
