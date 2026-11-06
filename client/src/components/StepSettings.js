import React from 'react';
import Icon from './Icon';

const STEP_TYPES = Object.freeze({
  START: 'start',
  APPROVAL: 'approval',
  NOTIFICATION: 'notification',
  PUBLISH: 'publish',
});

export default function StepSettings({ step, allSteps, meta, onChange, onRemove }) {
  return (
    <div className="workflow-settings">
      <h4 className="workflow-settings__title">Step Settings</h4>

      <div className="workflow-settings__field">
        <label className="workflow-settings__label" htmlFor="step-name">Name</label>
        <input
          id="step-name"
          className="workflow-settings__input"
          value={step.title}
          onChange={(e) => onChange({ ...step, title: e.target.value })}
        />
      </div>

      <div className="workflow-settings__field">
        <label className="workflow-settings__label" htmlFor="step-type">Type</label>
        <select
          id="step-type"
          className="workflow-settings__select"
          value={step.type || STEP_TYPES.APPROVAL}
          onChange={(e) => onChange({ ...step, type: e.target.value })}
        >
          <option value={STEP_TYPES.START}>Start</option>
          <option value={STEP_TYPES.APPROVAL}>Approval</option>
          <option value={STEP_TYPES.NOTIFICATION}>Notification</option>
          <option value={STEP_TYPES.PUBLISH}>Publish</option>
        </select>
      </div>

      <div className="workflow-settings__field">
        <label className="workflow-settings__label" htmlFor="step-group">Assign Group</label>
        <select
          id="step-group"
          className="workflow-settings__select"
          value={step.groupId || ''}
          onChange={(e) => onChange({ ...step, groupId: e.target.value })}
        >
          <option value="">No group assigned</option>
          {Object.entries(meta.groups || {}).map(([id, name]) => (
            <option key={id} value={id}>{name}</option>
          ))}
        </select>
      </div>

      {step.type === STEP_TYPES.APPROVAL && (
        <>
          <div className="workflow-settings__divider" />
          <h5 className="workflow-settings__subtitle">Transitions</h5>

          <div className="workflow-settings__field">
            <label className="workflow-settings__label" htmlFor="step-approve">
              <Icon name="check" /> Approve →
            </label>
            <select
              id="step-approve"
              className="workflow-settings__select"
              value={step.branches?.approve?.nextStepId || ''}
              onChange={(e) =>
                onChange({
                  ...step,
                  branches: {
                    ...step.branches,
                    approve: { nextStepId: e.target.value },
                  },
                })
              }
            >
              <option value="">Select target step</option>
              {allSteps
                .filter((s) => s.id !== step.id)
                .map((s) => (
                  <option key={s.id} value={s.id}>{s.title}</option>
                ))}
            </select>
          </div>

          <div className="workflow-settings__field">
            <label className="workflow-settings__label" htmlFor="step-reject">
              <Icon name="arrow-left" /> Reject →
            </label>
            <select
              id="step-reject"
              className="workflow-settings__select"
              value={step.branches?.reject?.nextStepId || ''}
              onChange={(e) =>
                onChange({
                  ...step,
                  branches: {
                    ...step.branches,
                    reject: { nextStepId: e.target.value },
                  },
                })
              }
            >
              <option value="">Select target step</option>
              {allSteps
                .filter((s) => s.id !== step.id)
                .map((s) => (
                  <option key={s.id} value={s.id}>{s.title}</option>
                ))}
            </select>
          </div>
        </>
      )}

      <div className="workflow-settings__divider" />

      <button
        className="workflow-settings__delete"
        onClick={onRemove}
        type="button"
        aria-label={`Delete step: ${step.title}`}
      >
        Delete Step
      </button>
    </div>
  );
}
