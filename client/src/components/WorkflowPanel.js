import React, { useEffect, useState, useCallback } from 'react';
import WorkflowStatusBar from './WorkflowStatusBar';
import WorkflowDetailPanel from './WorkflowDetailPanel';
import Icon from './Icon';
import { classifyWorkflowResponse, RESPONSE_NOT_APPLICABLE, RESPONSE_OK } from '../lib/workflowResponse';
import '../styles/workflow.scss';

const API_BASE = '/workflow-admin';

const WorkflowPanel = ({ recordID, className }) => {
  const [data, setData] = useState(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(false);

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

  const handleTransition = async (transitionId, comment) => {
    setActionLoading(true);

    try {
      const response = await fetch(`${API_BASE}/executeTransition`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          TransitionID: transitionId,
          Comment: comment || null,
        }),
      });

      if (!response.ok) {
        const result = await response.json();
        throw new Error(result.error || 'Failed to execute transition');
      }

      load();
    } finally {
      setActionLoading(false);
    }
  };

  const handleStartWorkflow = async (definitionId) => {
    setActionLoading(true);

    try {
      const response = await fetch(`${API_BASE}/startWorkflow`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          RecordID: recordID,
          ClassName: className,
          DefinitionID: definitionId || 0,
        }),
      });

      if (!response.ok) {
        const result = await response.json();
        throw new Error(result.error || 'Failed to start workflow');
      }

      load();
    } finally {
      setActionLoading(false);
    }
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
              <span className="workflow-bar__indicator" style={{ color: '#1976d2' }} aria-hidden="true"><Icon name="circle" /></span>
              <span className="workflow-bar__status">No active workflow</span>
            </div>
            <div className="workflow-bar__right">
              {availableWorkflows.map((wf) => (
                <button
                  key={wf.id}
                  className="workflow-bar__action-btn workflow-bar__action-btn--approve"
                  onClick={() => handleStartWorkflow(wf.id)}
                  disabled={actionLoading}
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
        actionLoading={actionLoading}
      />

      {detailOpen && (
        <WorkflowDetailPanel
          data={data}
          onClose={() => setDetailOpen(false)}
          onTransition={handleTransition}
          onRefresh={load}
        />
      )}
    </div>
  );
};

export default WorkflowPanel;
