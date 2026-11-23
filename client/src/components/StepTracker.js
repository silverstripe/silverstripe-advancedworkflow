import React from 'react';
import i18n from 'i18n';
import Icon from './Icon';

const statusLabel = (status) => ({
  completed: i18n._t('WorkflowPanel.STEP_COMPLETED', 'Completed'),
  active: i18n._t('WorkflowPanel.STEP_CURRENT', 'Current step'),
  pending: i18n._t('WorkflowPanel.STEP_PENDING', 'Pending'),
  rejected: i18n._t('WorkflowPanel.STEP_REJECTED', 'Rejected'),
}[status] || status);

const renderDotMark = (status) => {
  if (status === 'completed') return <Icon name="check" />;
  if (status === 'rejected') return <Icon name="cross" />;
  return null;
};

const getStepStatus = (step, index, currentIndex, isRejected) => {
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

const StepTracker = ({ steps, currentIndex, compact, isRejected }) => {
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
          const status = getStepStatus(step, i, currentIndex, isRejected);
          const tooltipText = `${step.title} — ${statusLabel(status)}`;

          return (
            <React.Fragment key={step.id}>
              <div
                className={`workflow-tracker__step workflow-tracker__step--${status}`}
                aria-current={i === currentIndex ? 'step' : undefined}
                title={tooltipText}
              >
                <span className="workflow-tracker__dot">
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
          {i18n.inject(
            i18n._t('WorkflowPanel.STEP_COUNT', 'Step {current} of {total}'),
            { current: currentIndex + 1, total: steps.length }
          )}
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
        const status = getStepStatus(step, i, currentIndex, isRejected);
        const tooltipText = `${step.title} — ${statusLabel(status)}`;

        return (
          <React.Fragment key={step.id}>
            <div
              className={`workflow-tracker__step workflow-tracker__step--${status}`}
              aria-current={i === currentIndex ? 'step' : undefined}
              title={tooltipText}
            >
              <span className="workflow-tracker__dot">
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
