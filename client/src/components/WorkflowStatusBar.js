import React, { useState } from 'react';
import StepTracker from './StepTracker';
import Icon from './Icon';

const WORKFLOW_STATUS = Object.freeze({
  ACTIVE: 'Active',
  PAUSED: 'Paused',
  COMPLETE: 'Complete',
  CANCELLED: 'Cancelled',
});

const STATUS_INDICATOR = Object.freeze({
  [WORKFLOW_STATUS.ACTIVE]: { color: '#f9a825', icon: 'dot', label: 'In Progress' },
  [WORKFLOW_STATUS.PAUSED]: { color: '#ff6f00', icon: 'pause', label: 'Paused' },
  [WORKFLOW_STATUS.COMPLETE]: { color: '#2e7d32', icon: 'check', label: 'Complete' },
  [WORKFLOW_STATUS.CANCELLED]: { color: '#c62828', icon: 'cross', label: 'Cancelled' },
});

const isRejected = (data) => data?.isRejected === true;
const isPublished = (data) => data?.current === 'Published';

const WorkflowStatusBar = ({ data, onViewDetails, onTransition, onStartWorkflow, actionLoading }) => {
  const [comment, setComment] = useState('');
  const [showComment, setShowComment] = useState(false);

  if (!data || !data.instanceId) return null;

  const status = STATUS_INDICATOR[data.workflowStatus] || STATUS_INDICATOR[WORKFLOW_STATUS.ACTIVE];
  const rejected = isRejected(data);
  const published = isPublished(data);
  const isFinished = data.workflowStatus === WORKFLOW_STATUS.COMPLETE
    || data.workflowStatus === WORKFLOW_STATUS.CANCELLED;

  let barModifier = '';
  if (rejected) {
    barModifier = 'workflow-bar--rejected';
  } else if (published) {
    barModifier = 'workflow-bar--published';
  } else if (isFinished) {
    barModifier = 'workflow-bar--complete';
  }

  let statusLabel = status.label;
  let statusIcon = status.icon;
  let statusColor = status.color;

  if (rejected) {
    statusLabel = 'Rejected';
    statusIcon = 'cross';
    statusColor = '#c62828';
  } else if (published) {
    statusLabel = 'Published';
    statusIcon = 'check';
    statusColor = '#2e7d32';
  }

  const nextTransitions = data.nextTransitions || [];
  const backTransitions = data.backTransitions || [];
  const availableWorkflows = data.availableWorkflows || [];
  const hasActions = !isFinished && (nextTransitions.length > 0 || backTransitions.length > 0);
  const allowCommenting = data.allowCommenting === true;

  const handleAction = (transitionId) => {
    if (onTransition) {
      onTransition(transitionId, comment);
      setComment('');
      setShowComment(false);
    }
  };

  return (
    <div className={`workflow-bar ${barModifier}`} role="status" aria-label="Workflow status">
      {/* Top row: status + progress + details */}
      <div className="workflow-bar__top">
        <div className="workflow-bar__left">
          <span
            className="workflow-bar__indicator"
            style={{ color: statusColor }}
            aria-hidden="true"
          >
            <Icon name={statusIcon} />
          </span>
          <span className="workflow-bar__status">
            {statusLabel}
          </span>
          {data.current && !published && (
            <>
              <span className="workflow-bar__separator" aria-hidden="true">•</span>
              <span className="workflow-bar__current">{data.current}</span>
            </>
          )}
        </div>

        <div className="workflow-bar__center">
          <StepTracker
            steps={data.steps || []}
            currentIndex={data.currentIndex || 0}
            compact={false}
            workflowStatus={data.workflowStatus}
            isRejected={rejected}
          />
        </div>

        <div className="workflow-bar__right">
          {data.assignedTo && (
            <span className="workflow-bar__assigned">
              Assigned: <strong>{data.assignedTo}</strong>
            </span>
          )}
          <button
            className="workflow-bar__details-btn"
            onClick={onViewDetails}
            type="button"
            aria-label="View workflow details"
          >
            View Details
          </button>
        </div>
      </div>

      {/* Action row: transition buttons below progress */}
      {hasActions && (
        <div className="workflow-bar__actions">
          <div className="workflow-bar__actions-left">
            {allowCommenting && showComment && (
              <input
                className="workflow-bar__comment-input"
                type="text"
                placeholder="Add a comment (optional)…"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                aria-label="Workflow comment"
              />
            )}
            {allowCommenting && !showComment && (
              <button
                className="workflow-bar__comment-toggle"
                onClick={() => setShowComment(true)}
                type="button"
                title="Add comment before action"
              >
                <Icon name="comment" /> Comment
              </button>
            )}
          </div>

          <div className="workflow-bar__actions-right">
            {nextTransitions.map((t) => (
              <button
                key={t.id}
                className="workflow-bar__action-btn workflow-bar__action-btn--approve"
                onClick={() => handleAction(t.id)}
                disabled={actionLoading}
                type="button"
              >
                {t.title}
              </button>
            ))}
            {backTransitions.map((t) => (
              <button
                key={t.id}
                className="workflow-bar__action-btn workflow-bar__action-btn--reject"
                onClick={() => handleAction(t.id)}
                disabled={actionLoading}
                type="button"
              >
                {t.title}
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Restart workflow when finished */}
      {isFinished && availableWorkflows.length > 0 && (
        <div className="workflow-bar__actions">
          <div className="workflow-bar__actions-left">
            <span className="workflow-bar__restart-hint">Content edited? Start a new approval cycle:</span>
          </div>
          <div className="workflow-bar__actions-right">
            {availableWorkflows.map((wf) => (
              <button
                key={wf.id}
                className="workflow-bar__action-btn workflow-bar__action-btn--approve"
                onClick={() => onStartWorkflow && onStartWorkflow(wf.id)}
                disabled={actionLoading}
                type="button"
              >
                {wf.title}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

export default WorkflowStatusBar;
