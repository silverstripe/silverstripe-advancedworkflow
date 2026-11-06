import React from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import Icon from './Icon';

const STEP_TYPE_ICONS = Object.freeze({
  start: 'play',
  approval: 'approval',
  notification: 'notification',
  publish: 'publish',
  custom: 'action',
});

export default function SortableStep({ step, steps, isSelected, onClick }) {
  const { attributes, listeners, setNodeRef, transform, transition } =
    useSortable({ id: step.id });

  const getTitle = (id) => {
    const s = steps.find((x) => x.id === id);
    return s ? s.title : '—';
  };

  const icon = STEP_TYPE_ICONS[step.type] || STEP_TYPE_ICONS.custom;

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  };

  const classNames = [
    'workflow-step',
    isSelected ? 'workflow-step--selected' : '',
  ].filter(Boolean).join(' ');

  return (
    <div
      id={`step-${step.id}`}
      ref={setNodeRef}
      {...attributes}
      {...listeners}
      onClick={onClick}
      className={classNames}
      style={style}
      role="button"
      tabIndex={0}
      aria-label={`Step: ${step.title}`}
      aria-pressed={isSelected}
    >
      <div className="workflow-step__header">
        <span className="workflow-step__icon" aria-hidden="true"><Icon name={icon} /></span>
        <strong className="workflow-step__title">{step.title}</strong>
        <span className="workflow-step__type">{step.type}</span>
      </div>

      {step.type === 'approval' && step.branches && (
        <div className="workflow-step__branches">
          {step.branches.approve && (
            <div className="workflow-step__branch workflow-step__branch--approve">
              <Icon name="check" /> Approve → {getTitle(step.branches.approve.nextStepId)}
            </div>
          )}
          {step.branches.reject && (
            <div className="workflow-step__branch workflow-step__branch--reject">
              <Icon name="arrow-left" /> Reject → {getTitle(step.branches.reject.nextStepId)}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
