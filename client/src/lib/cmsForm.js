import jQuery from 'jquery';

/**
 * Edit-form actions handled by AdvancedWorkflowExtension. Each saves the
 * record (or the workflow fields) before acting on the workflow.
 */
export const WORKFLOW_FORM_ACTION = Object.freeze({
  START: 'action_startworkflow',
  UPDATE: 'action_updateworkflow',
});

/**
 * Form field names those actions read.
 */
export const WORKFLOW_FORM_FIELD = Object.freeze({
  DEFINITION_ID: 'TriggeredWorkflowID',
  TRANSITION_ID: 'TransitionID',
  COMMENT: 'Comment',
});

const EDIT_FORM_SELECTOR = 'form.cms-edit-form';

const setFormValue = ($form, name, value) => {
  let $field = $form.find(`[name="${name}"]`);

  if (!$field.length) {
    $field = jQuery('<input type="hidden" />').attr('name', name).appendTo($form);
  }

  $field.val(value);
};

/**
 * Submits the CMS edit form that contains `button`, using the button's `name`
 * as the form action. Going through the CMS's own submitForm keeps unsaved
 * edits (including WYSIWYG content), shows the standard loading state and
 * reloads the form from the response, so it turns read-only once a workflow
 * takes over the record.
 *
 * @param {HTMLButtonElement} button the clicked button; its name is the action
 * @param {Object<string, string|number>} values form fields to set first
 * @returns {boolean} false when the button is not inside a CMS edit form
 */
export default function submitWorkflowAction(button, values = {}) {
  const $form = jQuery(button).closest(EDIT_FORM_SELECTOR);

  if (!$form.length) {
    return false;
  }

  Object.entries(values).forEach(([name, value]) => setFormValue($form, name, value));
  // submitForm calls the form's `ss`-namespaced methods (e.g. validate()), which
  // a plain jQuery object does not expose outside that namespace.
  jQuery('.cms-container').entwine('ss').submitForm($form.entwine('ss'), jQuery(button));

  return true;
}
