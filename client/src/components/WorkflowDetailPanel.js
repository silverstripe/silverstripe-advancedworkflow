import React, { useState, useEffect, useRef } from 'react';
import i18n from 'i18n';
import StepTracker from './StepTracker';
import ActivityFeed from './ActivityFeed';
import Icon from './Icon';
import FieldLabel from './FieldLabel';
import { WORKFLOW_FORM_ACTION } from '../lib/cmsForm';
import { transitionVariant } from '../lib/transitionVariant';

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

  const nextTransitions = data.nextTransitions || [];
  const backTransitions = data.backTransitions || [];

  return (
    <div className="workflow-overlay">
      {/* Mouse-only shortcut; keyboard users close with Escape or the close button. */}
      <div className="workflow-overlay__backdrop" onClick={onClose} aria-hidden="true" />
      <div
        className="workflow-detail"
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-label={i18n._t('WorkflowPanel.DETAILS_LABEL', 'Workflow details')}
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
              {isWorkflowRejected
                ? i18n._t('WorkflowPanel.REJECTED', 'Rejected')
                : (data.current || i18n._t('WorkflowPanel.WORKFLOW', 'Workflow'))}
              {isFinished && !isWorkflowRejected && ` ${i18n._t('WorkflowPanel.COMPLETE_SUFFIX', '(Complete)')}`}
            </h3>
          </div>
          <button
            className="workflow-detail__close"
            onClick={onClose}
            type="button"
            aria-label={i18n._t('WorkflowPanel.CLOSE_DETAILS', 'Close workflow details')}
          >
            <Icon name="cross" />
          </button>
        </div>

        {/* Content */}
        <div className="workflow-detail__body">
          {/* Rejection notice */}
          {isWorkflowRejected && (
            <div className="workflow-detail__section workflow-detail__notice workflow-detail__notice--rejected">
              <strong>{i18n._t('WorkflowPanel.WAS_REJECTED', 'This workflow was rejected.')}</strong>
              {data.history && data.history.length > 0 && data.history[0].author && (
                <p>
                  {i18n.inject(
                    i18n._t('WorkflowPanel.REJECTED_BY', 'By {author} on {date}'),
                    { author: data.history[0].author, date: data.history[0].date }
                  )}
                </p>
              )}
            </div>
          )}

          {/* Completed notice */}
          {isFinished && !isWorkflowRejected && (
            <div className="workflow-detail__section workflow-detail__notice workflow-detail__notice--complete">
              <strong>{i18n._t('WorkflowPanel.IS_COMPLETE', 'This workflow is complete.')}</strong>
            </div>
          )}
          {/* Assignment */}
          {data.assignedTo && (
            <div className="workflow-detail__section">
              <span className="workflow-detail__label">{i18n._t('WorkflowPanel.ASSIGNED_TO', 'Assigned to')}</span>
              <span className="workflow-detail__value">{data.assignedTo}</span>
            </div>
          )}

          {/* Step Tracker */}
          <div className="workflow-detail__section">
            <span className="workflow-detail__label">{i18n._t('WorkflowPanel.PROGRESS', 'Progress')}</span>
            <StepTracker
              steps={data.steps || []}
              currentIndex={data.currentIndex || 0}
              compact
              isRejected={isWorkflowRejected}
            />
          </div>

          {(nextTransitions.length > 0 || backTransitions.length > 0) && (
            <div className="workflow-detail__section">
              <span className="workflow-detail__label">{i18n._t('WorkflowPanel.ACTIONS', 'Actions')}</span>
              <div className="workflow-detail__actions">
                {[
                  ...nextTransitions.map((t) => [t, true]),
                  ...backTransitions.map((t) => [t, false]),
                ].map(([t, isForward]) => (
                  <button
                    key={t.id}
                    name={WORKFLOW_FORM_ACTION.UPDATE}
                    className={`workflow-detail__btn workflow-detail__btn--${transitionVariant(t, isForward)}`}
                    onClick={(e) => handleTransition(e.currentTarget, t.id)}
                    type="button"
                  >
                    {t.title}
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* Comment — only shown when action allows it and workflow is active */}
          {data.allowCommenting && !isFinished && (
            <div className="workflow-detail__section">
              <FieldLabel className="workflow-detail__label" htmlFor="workflow-comment">
                {i18n._t('WorkflowPanel.LEAVE_COMMENT', 'Leave a Comment')}
              </FieldLabel>
              <textarea
                id="workflow-comment"
                className="workflow-detail__textarea"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder={i18n._t('WorkflowPanel.COMMENT_PLACEHOLDER', 'Add feedback or notes for the team...')}
                rows={3}
              />
            </div>
          )}

          {/* Latest Feedback — standalone section showing most recent comment */}
          {data.latestComment && (
            <div className="workflow-detail__section">
              <span className="workflow-detail__label">{i18n._t('WorkflowPanel.LATEST_FEEDBACK', 'Latest Feedback')}</span>
              <div className="workflow-detail__feedback">
                <p className="workflow-detail__feedback-text">{data.latestComment.comment}</p>
                <span className="workflow-detail__feedback-meta">
                  {data.latestComment.author && <strong>{data.latestComment.author}</strong>}
                  {data.latestComment.author && data.latestComment.step && ` ${i18n._t('WorkflowPanel.ON', 'on')} `}
                  {data.latestComment.step && <em>{data.latestComment.step}</em>}
                </span>
              </div>
            </div>
          )}

          {/* Activity */}
          <div className="workflow-detail__section">
            <span className="workflow-detail__label">{i18n._t('WorkflowPanel.RECENT_ACTIVITY', 'Recent Activity')}</span>
            <ActivityFeed history={data.history || []} />
          </div>
        </div>
      </div>
    </div>
  );
};

export default WorkflowDetailPanel;
