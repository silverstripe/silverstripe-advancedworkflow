import React, { useState, useEffect, useRef } from 'react';
import StepTracker from './StepTracker';
import ActivityFeed from './ActivityFeed';
import Icon from './Icon';
import FieldLabel from './FieldLabel';
import { WORKFLOW_FORM_ACTION } from '../lib/cmsForm';

const WorkflowDetailPanel = ({ data, onClose, onTransition }) => {
  const [comment, setComment] = useState('');
  const panelRef = useRef(null);

  const isWorkflowRejected = data?.isRejected === true;

  const isFinished = data?.workflowStatus === 'Complete' || data?.workflowStatus === 'Cancelled';

  useEffect(() => {
    if (panelRef.current) {
      panelRef.current.focus();
    }

    const handleKeyDown = (e) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [onClose]);

  const handleTransition = (button, transitionId) => {
    onTransition(button, transitionId, comment);
  };

  if (!data) return null;

  return (
    <div className="workflow-overlay">
      {/* Mouse-only shortcut; keyboard users close with Escape or the close button. */}
      <div className="workflow-overlay__backdrop" onClick={onClose} aria-hidden="true" />
      <div
        className="workflow-detail"
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-label="Workflow details"
        aria-modal="true"
      >
        {/* Header */}
        <div className={`workflow-detail__header ${isWorkflowRejected ? 'workflow-detail__header--rejected' : ''}`}>
          <div className="workflow-detail__title">
            <span
              className={`workflow-detail__indicator ${isWorkflowRejected ? 'workflow-detail__indicator--rejected' : ''}`}
              aria-hidden="true"
            >
              <Icon name={isWorkflowRejected ? 'cross' : 'dot'} />
            </span>
            <h3>
              {isWorkflowRejected ? 'Rejected' : (data.current || 'Workflow')}
              {isFinished && !isWorkflowRejected && ' (Complete)'}
            </h3>
          </div>
          <button
            className="workflow-detail__close"
            onClick={onClose}
            type="button"
            aria-label="Close workflow details"
          >
            <Icon name="cross" />
          </button>
        </div>

        {/* Content */}
        <div className="workflow-detail__body">
          {/* Rejection notice */}
          {isWorkflowRejected && (
            <div className="workflow-detail__section workflow-detail__notice workflow-detail__notice--rejected">
              <strong>This workflow was rejected.</strong>
              {data.history && data.history.length > 0 && data.history[0].author && (
                <p>By {data.history[0].author} on {data.history[0].date}</p>
              )}
            </div>
          )}

          {/* Completed notice */}
          {isFinished && !isWorkflowRejected && (
            <div className="workflow-detail__section workflow-detail__notice workflow-detail__notice--complete">
              <strong>This workflow is complete.</strong>
            </div>
          )}
          {/* Assignment */}
          {data.assignedTo && (
            <div className="workflow-detail__section">
              <span className="workflow-detail__label">Assigned to</span>
              <span className="workflow-detail__value">{data.assignedTo}</span>
            </div>
          )}

          {/* Step Tracker */}
          <div className="workflow-detail__section">
            <span className="workflow-detail__label">Progress</span>
            <StepTracker
              steps={data.steps || []}
              currentIndex={data.currentIndex || 0}
              compact
              isRejected={isWorkflowRejected}
            />
          </div>

          {/* Forward Transitions */}
          {data.nextTransitions && data.nextTransitions.length > 0 && (
            <div className="workflow-detail__section">
              <span className="workflow-detail__label">Actions</span>
              <div className="workflow-detail__actions">
                {data.nextTransitions.map((t) => (
                  <button
                    key={t.id}
                    name={WORKFLOW_FORM_ACTION.UPDATE}
                    className="workflow-detail__btn workflow-detail__btn--next"
                    onClick={(e) => handleTransition(e.currentTarget, t.id)}
                    type="button"
                  >
                    <Icon name="check" /> {t.title}
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* Back Transitions */}
          {data.backTransitions && data.backTransitions.length > 0 && (
            <div className="workflow-detail__section">
              <div className="workflow-detail__actions">
                {data.backTransitions.map((t) => (
                  <button
                    key={t.id}
                    name={WORKFLOW_FORM_ACTION.UPDATE}
                    className="workflow-detail__btn workflow-detail__btn--back"
                    onClick={(e) => handleTransition(e.currentTarget, t.id)}
                    type="button"
                  >
                    <Icon name="arrow-left" /> {t.title}
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* Comment — only shown when action allows it and workflow is active */}
          {data.allowCommenting && !isFinished && (
            <div className="workflow-detail__section">
              <FieldLabel className="workflow-detail__label" htmlFor="workflow-comment">
                Leave a Comment
              </FieldLabel>
              <textarea
                id="workflow-comment"
                className="workflow-detail__textarea"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Add feedback or notes for the team..."
                rows={3}
              />
            </div>
          )}

          {/* Latest Feedback — standalone section showing most recent comment */}
          {data.latestComment && (
            <div className="workflow-detail__section">
              <span className="workflow-detail__label">Latest Feedback</span>
              <div className="workflow-detail__feedback">
                <p className="workflow-detail__feedback-text">{data.latestComment.comment}</p>
                <span className="workflow-detail__feedback-meta">
                  {data.latestComment.author && <strong>{data.latestComment.author}</strong>}
                  {data.latestComment.author && data.latestComment.step && ' on '}
                  {data.latestComment.step && <em>{data.latestComment.step}</em>}
                </span>
              </div>
            </div>
          )}

          {/* Activity */}
          <div className="workflow-detail__section">
            <span className="workflow-detail__label">Recent Activity</span>
            <ActivityFeed history={data.history || []} />
          </div>
        </div>
      </div>
    </div>
  );
};

export default WorkflowDetailPanel;
