import React, { useState } from 'react';
import Icon from './Icon';

const ACTION_ICONS = Object.freeze({
  approval: 'approval',
  rejection: 'cross',
  notification: 'notification',
  assignment: 'assignment',
  publish: 'publish',
  action: 'action',
});

const ACTION_LABELS = Object.freeze({
  approval: 'Approval',
  rejection: 'Rejection',
  notification: 'Notification',
  assignment: 'Assignment',
  publish: 'Publish',
  action: 'Action',
});

const formatRelativeDate = (dateStr) => {
  if (!dateStr) return '';

  const date = new Date(dateStr.replace(' ', 'T'));
  const now = new Date();
  const diffMs = now - date;
  const diffSecs = Math.floor(diffMs / 1000);
  const diffMins = Math.floor(diffSecs / 60);
  const diffHours = Math.floor(diffMins / 60);
  const diffDays = Math.floor(diffHours / 24);

  if (diffSecs < 60) return 'Just now';
  if (diffMins < 60) return `${diffMins}m ago`;
  if (diffHours < 24) return `${diffHours}h ago`;
  if (diffDays < 7) return `${diffDays}d ago`;

  return date.toLocaleDateString('en-NZ', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
};

const formatFullDate = (dateStr) => {
  if (!dateStr) return '';

  const date = new Date(dateStr.replace(' ', 'T'));

  return date.toLocaleDateString('en-NZ', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const ActivityEntry = ({ entry }) => {
  const hasComment = entry.comment && entry.comment.trim() !== '';
  const [expanded, setExpanded] = useState(hasComment);

  const icon = ACTION_ICONS[entry.actionType] || ACTION_ICONS.action;
  const typeLabel = ACTION_LABELS[entry.actionType] || 'Action';
  const isRejection = entry.actionType === 'rejection';

  return (
    <li
      className={`workflow-activity__item ${expanded ? 'workflow-activity__item--expanded' : ''} ${isRejection ? 'workflow-activity__item--rejection' : ''} ${hasComment ? 'workflow-activity__item--has-comment' : ''}`}
    >
      <button
        className="workflow-activity__toggle"
        onClick={() => setExpanded(!expanded)}
        type="button"
        aria-expanded={expanded}
        title="Click for details"
      >
        <span className="workflow-activity__icon" aria-hidden="true"><Icon name={icon} /></span>
        <div className="workflow-activity__summary">
          <span className="workflow-activity__title">
            {entry.title}
            {hasComment && !expanded && (
              <span className="workflow-activity__comment-hint"><Icon name="comment" title="Has feedback" /></span>
            )}
          </span>
          <span className="workflow-activity__meta">
            <span className="workflow-activity__type-badge">{typeLabel}</span>
            {entry.author && (
              <span className="workflow-activity__author">{entry.author}</span>
            )}
            {entry.date && (
              <time className="workflow-activity__time" dateTime={entry.date} title={formatFullDate(entry.date)}>
                {formatRelativeDate(entry.date)}
              </time>
            )}
          </span>
        </div>
        <span className="workflow-activity__chevron" aria-hidden="true">
          <Icon name={expanded ? 'chevron-down' : 'chevron-right'} />
        </span>
      </button>

      {expanded && (
        <div className="workflow-activity__details">
          {hasComment && (
            <div className="workflow-activity__detail-row">
              <span className="workflow-activity__detail-label">Feedback</span>
              <p className="workflow-activity__detail-comment">{entry.comment}</p>
            </div>
          )}
          {entry.author && (
            <div className="workflow-activity__detail-row">
              <span className="workflow-activity__detail-label">By</span>
              <span className="workflow-activity__detail-value">{entry.author}</span>
            </div>
          )}
          {entry.date && (
            <div className="workflow-activity__detail-row">
              <span className="workflow-activity__detail-label">Date</span>
              <span className="workflow-activity__detail-value">{formatFullDate(entry.date)}</span>
            </div>
          )}
          {!hasComment && !entry.author && (
            <p className="workflow-activity__detail-empty">No additional details recorded.</p>
          )}
        </div>
      )}
    </li>
  );
};

const ActivityFeed = ({ history }) => {
  if (!history || history.length === 0) {
    return (
      <div className="workflow-activity">
        <p className="workflow-activity__empty">No activity yet.</p>
      </div>
    );
  }

  return (
    <div className="workflow-activity" role="log" aria-label="Workflow activity">
      <ul className="workflow-activity__list">
        {history.map((entry, i) => (
          <ActivityEntry key={i} entry={entry} />
        ))}
      </ul>
    </div>
  );
};

export default ActivityFeed;
