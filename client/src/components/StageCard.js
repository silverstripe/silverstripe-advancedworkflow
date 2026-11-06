import React from 'react';
import Icon from './Icon';
import { STEP_TYPES, STAGE_KIND } from '../lib/workflowStages';
import { metaFor } from '../lib/stepMeta';

// The two standard support steps a review stage can carry, in order.
const STANDARD_SUPPORT = Object.freeze([STEP_TYPES.ASSIGN, STEP_TYPES.NOTIFICATION]);

const targetLabel = (id, stepTitle, emptyText) => (id ? stepTitle(id) : emptyText);

/**
 * Renders a single stage as a card: its ordered steps, the add-ons
 * that can still be attached, and where each approval outcome leads.
 * Purely presentational — all mutations are delegated to callbacks.
 */
export default function StageCard({
  stage,
  number,
  selectedId,
  stepTitle,
  onSelectStep,
  onAddSupport,
  onRemoveSupport,
  onRemoveStage,
}) {
  const isPublish = stage.kind === STAGE_KIND.PUBLISH;

  const renderSubStep = (step, { isGate = false, removable = false } = {}) => {
    const meta = metaFor(step.type);
    const classes = [
      'wf-builder__substep',
      isGate ? 'wf-builder__substep--gate' : '',
      selectedId === step.id ? 'wf-builder__substep--selected' : '',
    ].filter(Boolean).join(' ');

    return (
      <div
        key={step.id}
        className={classes}
        onClick={() => onSelectStep(step.id)}
        role="button"
        tabIndex={0}
        aria-selected={selectedId === step.id}
      >
        <span className="wf-builder__substep-icon"><Icon name={meta.icon} /></span>
        <div className="wf-builder__substep-info">
          <span className="wf-builder__substep-title">{step.title}</span>
          <span className="wf-builder__substep-role">{meta.role}</span>
        </div>
        {isGate && (
          <span className="wf-builder__gate-badge" title="The workflow waits here until someone approves or rejects">
            <Icon name="pause" /> Waits for a decision
          </span>
        )}
        {removable && (
          <button
            type="button"
            className="wf-builder__substep-remove"
            title="Remove this step from the stage"
            onClick={(e) => { e.stopPropagation(); onRemoveSupport(stage.id, step.type); }}
          >
            <Icon name="cross" />
          </button>
        )}
      </div>
    );
  };

  const missingSupport = STANDARD_SUPPORT.filter(
    (type) => !stage.support.some((s) => s.type === type)
  );

  const renderAddOns = () => {
    if (missingSupport.length === 0) {
      return null;
    }

    return (
      <div className="wf-builder__stage-addons">
        <span className="wf-builder__addons-label">Add to this stage:</span>
        {missingSupport.map((type) => {
          const meta = metaFor(type);
          return (
            <button
              key={type}
              type="button"
              className="wf-builder__addon-chip"
              title={meta.description}
              onClick={() => onAddSupport(stage.id, type)}
            >
              <Icon name="plus" /> {meta.label}
            </button>
          );
        })}
      </div>
    );
  };

  const renderBranches = () => (
    <div className="wf-builder__stage-branches">
      <span className="wf-builder__branch wf-builder__branch--approve">
        <Icon name="check" /> If approved → <strong>{targetLabel(stage.approveTargetId, stepTitle, 'End of workflow')}</strong>
      </span>
      <span className="wf-builder__branch wf-builder__branch--reject">
        <Icon name="cross" /> If rejected → <strong>{targetLabel(stage.rejectTargetId, stepTitle, 'End of workflow')}</strong>
      </span>
    </div>
  );

  return (
    <div className={`wf-builder__stage ${isPublish ? 'wf-builder__stage--publish' : 'wf-builder__stage--review'}`}>
      <div className="wf-builder__stage-head">
        <span className="wf-builder__stage-num">{number}</span>
        <div className="wf-builder__stage-heading">
          <span className="wf-builder__stage-title">
            {isPublish ? 'Publish when approved' : `Stage ${number}: Review`}
          </span>
          <span className="wf-builder__stage-sub">
            {isPublish
              ? 'The content goes live once the workflow reaches this point.'
              : 'Reviewers are assigned and notified, then must approve or reject.'}
          </span>
        </div>
        <button
          type="button"
          className="wf-builder__stage-remove"
          title="Remove this whole stage"
          onClick={() => onRemoveStage(stage.id)}
        >
          <Icon name="trash" />
        </button>
      </div>

      <div className="wf-builder__stage-steps">
        {!isPublish && stage.support.map((step) => renderSubStep(step, { removable: true }))}
        {renderSubStep(stage.anchor, { isGate: !isPublish })}
      </div>

      {!isPublish && renderAddOns()}
      {!isPublish && renderBranches()}
    </div>
  );
}
