import React from 'react';
import ReactDOM from 'react-dom';
import jQuery from 'jquery';
import WorkflowPanel from './components/WorkflowPanel';
import WorkflowBuilder from './components/WorkflowBuilder';

const PANEL_ROOT_ID = 'workflow-panel-root';
const BUILDER_ROOT_ID = 'workflow-builder-root';

const mountWorkflowPanel = (el) => {
  if (!el || el.dataset.workflowMounted) return;

  const recordId = el.dataset.recordId;
  const className = el.dataset.className;

  if (!recordId || !className) return;

  el.dataset.workflowMounted = 'true';

  ReactDOM.render(
    <WorkflowPanel recordID={recordId} className={className} />,
    el
  );
};

const mountWorkflowBuilder = (el) => {
  if (!el || el.dataset.workflowMounted) return;

  el.dataset.workflowMounted = 'true';

  ReactDOM.render(
    <WorkflowBuilder definitionId={el.dataset.definitionId || null} />,
    el
  );
};

// Use Entwine to hook into the PJAX content lifecycle.
// Entwine's onmatch fires whenever a matching element enters the DOM,
// including after PJAX navigation replaces form content.
jQuery.entwine('ss.workflow', ($) => {
  $(`#${PANEL_ROOT_ID}`).entwine({
    onmatch() {
      mountWorkflowPanel(this[0]);
    },
    onunmatch() {
      const el = this[0];
      if (el) {
        try {
          ReactDOM.unmountComponentAtNode(el);
        } catch (e) {
          // Component may already be unmounted
        }
      }
    },
  });

  $(`#${BUILDER_ROOT_ID}`).entwine({
    onmatch() {
      mountWorkflowBuilder(this[0]);
    },
    onunmatch() {
      const el = this[0];
      if (el) {
        try {
          ReactDOM.unmountComponentAtNode(el);
        } catch (e) {
          // Component may already be unmounted
        }
      }
    },
  });
});
