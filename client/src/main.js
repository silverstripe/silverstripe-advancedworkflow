import React from 'react';
import { createRoot } from 'react-dom/client';
import jQuery from 'jquery';
import WorkflowPanel from './components/WorkflowPanel';
import WorkflowBuilder from './components/WorkflowBuilder';

const PANEL_ROOT_ID = 'workflow-panel-root';
const BUILDER_ROOT_ID = 'workflow-builder-root';

// React roots keyed by their container so PJAX navigation can unmount them.
const roots = new WeakMap();

const mount = (el, element) => {
  if (!el || el.dataset.workflowMounted) {
    return;
  }

  el.dataset.workflowMounted = 'true';
  const root = createRoot(el);
  roots.set(el, root);
  root.render(element);
};

const unmount = (el) => {
  const root = el ? roots.get(el) : null;

  if (root) {
    root.unmount();
    roots.delete(el);
  }
};

const mountWorkflowPanel = (el) => {
  const { recordId, className } = el ? el.dataset : {};

  if (!recordId || !className) {
    return;
  }

  mount(el, <WorkflowPanel recordID={recordId} className={className} />);
};

const mountWorkflowBuilder = (el) => {
  mount(el, <WorkflowBuilder definitionId={el ? el.dataset.definitionId || null : null} />);
};

// Entwine's onmatch fires whenever a matching element enters the DOM,
// including after PJAX navigation replaces the edit form.
jQuery.entwine('ss.workflow', ($) => {
  $(`#${PANEL_ROOT_ID}`).entwine({
    onmatch() {
      mountWorkflowPanel(this[0]);
    },
    onunmatch() {
      unmount(this[0]);
    },
  });

  $(`#${BUILDER_ROOT_ID}`).entwine({
    onmatch() {
      mountWorkflowBuilder(this[0]);
    },
    onunmatch() {
      unmount(this[0]);
    },
  });
});
