import React from 'react';
import Icon from './Icon';

const STATUS_COLORS = Object.freeze({
  completed: '#2e7d32',
  active: '#1976d2',
  pending: '#9e9e9e',
  rejected: '#c62828',
});

const STATUS_LABELS = Object.freeze({
  completed: 'Completed',
  active: 'Current step',
  pending: 'Pending',
  rejected: 'Rejected',
});

const renderDotMark = (status) => {
  if (status === 'completed') return <Icon name="check" />;
  if (status === 'rejected') return <Icon name="cross" />;
  return null;
};

const getStepStatus = (step, index, currentIndex, isRejected, workflowStatus) => {
  // Use backend-provided status directly when available
  if (step.status) {
    return step.status;
  }

  // Fallback for when backend doesn't provide status
  if (index < currentIndex) return 'completed';
  if (index === currentIndex) {
    if (isRejected) return 'rejected';
    return 'active';
  }
  return 'pending';
};

const StepTracker = ({ steps, currentIndex, compact, workflowStatus, isRejected }) => {
  if (!steps || steps.length === 0) return null;

  if (compact) {
    return (
      <div
        className="workflow-tracker workflow-tracker--compact"
        role="progressbar"
        aria-valuenow={currentIndex + 1}
        aria-valuemin={1}
        aria-valuemax={steps.length}
      >
        {steps.map((step, i) => {
          const status = getStepStatus(step, i, currentIndex, isRejected, workflowStatus);
          const tooltipText = `${step.title} — ${STATUS_LABELS[status] || status}`;

          return (
            <React.Fragment key={step.id}>
              <div
                className={`workflow-tracker__step workflow-tracker__step--${status}`}
                aria-current={i === currentIndex ? 'step' : undefined}
                title={tooltipText}
              >
                <span
                  className="workflow-tracker__dot"
                  style={{ backgroundColor: STATUS_COLORS[status] }}
                >
                  {renderDotMark(status)}
                </span>
              </div>
              {i < steps.length - 1 && (
                <div
                  className={`workflow-tracker__connector workflow-tracker__connector--${
                    i < currentIndex ? 'completed' : 'pending'
                  }`}
                />
              )}
            </React.Fragment>
          );
        })}
        <span className="workflow-tracker__count">
          Step {currentIndex + 1} of {steps.length}
        </span>
      </div>
    );
  }

  // Full mode — show all labels, fit the available space
  return (
    <div
      className="workflow-tracker workflow-tracker--full"
      role="progressbar"
      aria-valuenow={currentIndex + 1}
      aria-valuemin={1}
      aria-valuemax={steps.length}
    >
      {steps.map((step, i) => {
        const status = getStepStatus(step, i, currentIndex, isRejected, workflowStatus);
        const tooltipText = `${step.title} — ${STATUS_LABELS[status] || status}`;

        return (
          <React.Fragment key={step.id}>
            <div
              className={`workflow-tracker__step workflow-tracker__step--${status}`}
              aria-current={i === currentIndex ? 'step' : undefined}
              title={tooltipText}
            >
              <span
                className="workflow-tracker__dot"
                style={{ backgroundColor: STATUS_COLORS[status] }}
              >
                {renderDotMark(status) || i + 1}
              </span>
              <span className="workflow-tracker__label">{step.title}</span>
            </div>
            {i < steps.length - 1 && (
              <div
                className={`workflow-tracker__connector workflow-tracker__connector--${
                  i < currentIndex ? 'completed' : 'pending'
                }`}
              />
            )}
          </React.Fragment>
        );
      })}
    </div>
  );
};

export default StepTracker;
