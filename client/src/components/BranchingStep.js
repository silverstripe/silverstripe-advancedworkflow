import React from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import Icon from './Icon';

export default function BranchingStep({ step, steps, onClick }) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition
  } = useSortable({ id: step.id });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    padding: 12,
    marginBottom: 20,
    border: '1px solid #ddd',
    borderRadius: 8,
    background: '#fff'
  };

  const getStepTitle = (id) => {
    const s = steps.find(x => x.id === id);
    return s ? s.title : '—';
  };

  return (
    <div ref={setNodeRef} style={style} {...attributes} {...listeners} onClick={onClick}>
      <strong>{step.title}</strong>
      <div style={{ fontSize: 12 }}>Assigned: {step.assignedTo || '—'}</div>

      {/* Branching */}
      {step.type === 'approval' && (
        <div style={{ marginTop: 10, fontSize: 12 }}>
          <div><Icon name="check" /> Approve → {getStepTitle(step.branches?.approve?.nextStepId)}</div>
          <div><Icon name="request-changes" /> Request Changes → {getStepTitle(step.branches?.reject?.nextStepId)}</div>
        </div>
      )}
    </div>
  );
}
