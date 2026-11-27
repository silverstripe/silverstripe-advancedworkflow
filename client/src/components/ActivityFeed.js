import React, { useState } from 'react';
import i18n from 'i18n';
import Icon from './Icon';

const ACTION_ICONS = Object.freeze({
  approval: 'approval',
  rejection: 'cross',
  notification: 'notification',
  assignment: 'assignment',
  publish: 'publish',
  action: 'action',
});

const actionLabel = (actionType) => ({
  approval: i18n._t('WorkflowPanel.ACTIVITY_APPROVAL', 'Approval'),
  rejection: i18n._t('WorkflowPanel.ACTIVITY_REJECTION', 'Rejection'),
  notification: i18n._t('WorkflowPanel.ACTIVITY_NOTIFICATION', 'Notification'),
  assignment: i18n._t('WorkflowPanel.ACTIVITY_ASSIGNMENT', 'Assignment'),
  publish: i18n._t('WorkflowPanel.ACTIVITY_PUBLISH', 'Publish'),
}[actionType] || i18n._t('WorkflowPanel.ACTIVITY_ACTION', 'Action'));

// Dates follow the browser's locale.
const DATE_LOCALE = undefined;

const formatRelativeDate = (dateStr) => {
  if (!dateStr) return '';

  const date = new Date(dateStr.replace(' ', 'T'));
  const now = new Date();
  const diffMs = now - date;
  const diffSecs = Math.floor(diffMs / 1000);
  const diffMins = Math.floor(diffSecs / 60);
  const diffHours = Math.floor(diffMins / 60);
  const diffDays = Math.floor(diffHours / 24);

  if (diffSecs < 60) return i18n._t('WorkflowPanel.JUST_NOW', 'Just now');
  if (diffMins < 60) return i18n.inject(i18n._t('WorkflowPanel.MINUTES_AGO', '{count}m ago'), { count: diffMins });
  if (diffHours < 24) return i18n.inject(i18n._t('WorkflowPanel.HOURS_AGO', '{count}h ago'), { count: diffHours });
  if (diffDays < 7) return i18n.inject(i18n._t('WorkflowPanel.DAYS_AGO', '{count}d ago'), { count: diffDays });

  return date.toLocaleDateString(DATE_LOCALE, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
};

const formatFullDate = (dateStr) => {
  if (!dateStr) return '';

  const date = new Date(dateStr.replace(' ', 'T'));

  return date.toLocaleDateString(DATE_LOCALE, {
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
  const typeLabel = actionLabel(entry.actionType);
  const isRejection = entry.actionType === 'rejection';

  return (
    <li
      className={[
        'workflow-activity__item',
        expanded ? 'workflow-activity__item--expanded' : '',
        isRejection ? 'workflow-activity__item--rejection' : '',
        hasComment ? 'workflow-activity__item--has-comment' : '',
      ].filter(Boolean).join(' ')}
    >
      <button
        className="workflow-activity__toggle"
        onClick={() => setExpanded(!expanded)}
        type="button"
        aria-expanded={expanded}
        title={i18n._t('WorkflowPanel.SHOW_ACTIVITY_DETAILS', 'Click for details')}
      >
        <span className="workflow-activity__icon" aria-hidden="true"><Icon name={icon} /></span>
        <div className="workflow-activity__summary">
          <span className="workflow-activity__title">
            {entry.title}
            {hasComment && !expanded && (
              <span className="workflow-activity__comment-hint"><Icon name="comment" title={i18n._t('WorkflowPanel.HAS_FEEDBACK', 'Has feedback')} /></span>
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
              <span className="workflow-activity__detail-label">{i18n._t('WorkflowPanel.FEEDBACK', 'Feedback')}</span>
              <p className="workflow-activity__detail-comment">{entry.comment}</p>
            </div>
          )}
          {entry.author && (
            <div className="workflow-activity__detail-row">
              <span className="workflow-activity__detail-label">{i18n._t('WorkflowPanel.BY', 'By')}</span>
              <span className="workflow-activity__detail-value">{entry.author}</span>
            </div>
          )}
          {entry.date && (
            <div className="workflow-activity__detail-row">
              <span className="workflow-activity__detail-label">{i18n._t('WorkflowPanel.DATE', 'Date')}</span>
              <span className="workflow-activity__detail-value">{formatFullDate(entry.date)}</span>
            </div>
          )}
          {!hasComment && !entry.author && (
            <p className="workflow-activity__detail-empty">
              {i18n._t('WorkflowPanel.NO_ACTIVITY_DETAILS', 'No additional details recorded.')}
            </p>
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
        <p className="workflow-activity__empty">{i18n._t('WorkflowPanel.NO_ACTIVITY', 'No activity yet.')}</p>
      </div>
    );
  }

  return (
    <div className="workflow-activity" role="log" aria-label={i18n._t('WorkflowPanel.ACTIVITY_LABEL', 'Workflow activity')}>
      <ul className="workflow-activity__list">
        {history.map((entry) => (
          <ActivityEntry key={entry.id} entry={entry} />
        ))}
      </ul>
    </div>
  );
};

export default ActivityFeed;
