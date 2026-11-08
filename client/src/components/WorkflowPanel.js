import React, { useEffect, useState, useCallback } from 'react';
import WorkflowStatusBar from './WorkflowStatusBar';
import WorkflowDetailPanel from './WorkflowDetailPanel';
import Icon from './Icon';
import { classifyWorkflowResponse, RESPONSE_NOT_APPLICABLE, RESPONSE_OK } from '../lib/workflowResponse';
import submitWorkflowAction, { WORKFLOW_FORM_ACTION, WORKFLOW_FORM_FIELD } from '../lib/cmsForm';

const API_BASE = '/workflow-admin';

const WorkflowPanel = ({ recordID, className }) => {
  const [data, setData] = useState(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    const query = `ID=${encodeURIComponent(recordID)}&ClassName=${encodeURIComponent(className)}`;

    setLoading(true);
    fetch(`${API_BASE}/workflowData?${query}`, { credentials: 'same-origin' })
      .then((res) => {
        const outcome = classifyWorkflowResponse(res.status);

        if (outcome === RESPONSE_NOT_APPLICABLE) return null;
        if (outcome !== RESPONSE_OK) throw new Error(`Failed to load workflow data (${res.status})`);

        return res.json();
      })
      .then((result) => {
        setData(result);
        setError(null);
      })
      .catch((err) => {
        setError(err.message);
      })
      .finally(() => {
        setLoading(false);
      });
  }, [recordID, className]);

  useEffect(() => {
    load();
  }, [load]);

  // Workflow actions go through the edit form so unsaved edits are kept and
  // the form reloads (read-only once the workflow owns the record).
  const handleTransition = (button, transitionId, comment) => {
    submitWorkflowAction(button, {
      [WORKFLOW_FORM_FIELD.TRANSITION_ID]: transitionId,
      [WORKFLOW_FORM_FIELD.COMMENT]: comment || '',
    });
  };

  const handleStartWorkflow = (button, definitionId) => {
    submitWorkflowAction(button, {
      [WORKFLOW_FORM_FIELD.DEFINITION_ID]: definitionId,
    });
  };

  if (loading) {
    return null;
  }

  if (error) {
    return (
      <div className="workflow-panel workflow-panel--error" role="alert">
        <span>Unable to load workflow status.</span>
      </div>
    );
  }

  // No active workflow — show start button if definitions are available
  if (!data || data.workflow === null || (!data.current && !data.instanceId)) {
    const availableWorkflows = data?.availableWorkflows || [];

    if (availableWorkflows.length === 0) {
      return null;
    }

    return (
      <div className="workflow-panel">
        <div className="workflow-bar workflow-bar--start" role="status">
          <div className="workflow-bar__top">
            <div className="workflow-bar__left">
              <span className="workflow-bar__indicator workflow-bar__indicator--info" aria-hidden="true"><Icon name="circle" /></span>
              <span className="workflow-bar__status">No active workflow</span>
            </div>
            <div className="workflow-bar__right">
              {availableWorkflows.map((wf) => (
                <button
                  key={wf.id}
                  name={WORKFLOW_FORM_ACTION.START}
                  className="workflow-bar__action-btn workflow-bar__action-btn--approve"
                  onClick={(e) => handleStartWorkflow(e.currentTarget, wf.id)}
                  type="button"
                >
                  {wf.title}
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="workflow-panel">
      <WorkflowStatusBar
        data={data}
        onViewDetails={() => setDetailOpen(true)}
        onTransition={handleTransition}
        onStartWorkflow={handleStartWorkflow}
      />

      {detailOpen && (
        <WorkflowDetailPanel
          data={data}
          onClose={() => setDetailOpen(false)}
          onTransition={handleTransition}
        />
      )}
    </div>
  );
};

export default WorkflowPanel;
