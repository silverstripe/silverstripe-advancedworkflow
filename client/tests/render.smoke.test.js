import React from 'react';
import { renderToString } from 'react-dom/server';
import StageCard from '../src/components/StageCard';
import WorkflowBuilder from '../src/components/WorkflowBuilder';
import WorkflowStatusBar from '../src/components/WorkflowStatusBar';
import { buildStages, STEP_TYPES } from '../src/lib/workflowStages';

// A smoke render catches JSX / prop / import errors that a webpack
// build does not (the build type-checks nothing at runtime). Effects
// (fetch) do not run during server rendering, so no network is needed.

const reviewStage = () => buildStages([
  { id: '1', title: 'Assign reviewers', type: STEP_TYPES.ASSIGN, config: {}, transitions: [{ id: 't1', title: 'Next', nextStepId: '2' }] },
  { id: '2', title: 'Notify reviewers', type: STEP_TYPES.NOTIFICATION, config: {}, transitions: [{ id: 't2', title: 'Next', nextStepId: '3' }] },
  { id: '3', title: 'Review & approve', type: STEP_TYPES.APPROVAL, config: {}, transitions: [{ id: 't3', title: 'Approve', nextStepId: '' }, { id: 't4', title: 'Reject', nextStepId: '4' }] },
  { id: '4', title: 'Reject changes', type: STEP_TYPES.CANCEL, config: {}, transitions: [] },
]).stages[0];

describe('component smoke rendering', () => {
  it('renders a StageCard without throwing', () => {
    const html = renderToString(
      <StageCard
        stage={reviewStage()}
        number={1}
        selectedId={null}
        stepTitle={() => 'Reject changes'}
        onSelectStep={() => {}}
        onAddSupport={() => {}}
        onRemoveSupport={() => {}}
        onRemoveStage={() => {}}
      />
    );
    expect(html).toContain('Stage 1');
    expect(html).toContain('Waits for a decision');
  });

  it('renders WorkflowBuilder empty state without throwing', () => {
    const html = renderToString(<WorkflowBuilder definitionId={null} />);
    expect(html).toContain('Visual Workflow Builder');
    expect(html).toContain('Add your first review stage');
  });

  it('shows a loading state, not the empty state, while a definition loads', () => {
    const html = renderToString(<WorkflowBuilder definitionId="5" />);
    expect(html).toContain('Loading workflow');
    expect(html).not.toContain('No stages yet');
    expect(html).toMatch(/<button[^>]*disabled=""[^>]*>Save Workflow/);
  });

  it('labels a waiting workflow as awaiting approval', () => {
    const html = renderToString(<WorkflowStatusBar data={{ instanceId: 1, workflowStatus: 'Paused', steps: [] }} />);
    expect(html).toContain('Awaiting approval');
    expect(html).not.toContain('Paused');
  });

  it('reads the published state from the flag, not the translated label', () => {
    const html = renderToString(
      <WorkflowStatusBar data={{ instanceId: 1, workflowStatus: 'Complete', isPublished: true, current: 'Publié', steps: [] }} />
    );
    expect(html).toContain('workflow-bar--published');
  });

  it('hides View Details from users who cannot view the workflow', () => {
    const data = { instanceId: 1, workflowStatus: 'Paused', steps: [] };
    expect(renderToString(<WorkflowStatusBar data={data} />)).not.toContain('View Details');
    expect(renderToString(<WorkflowStatusBar data={{ ...data, canViewDetails: true }} />)).toContain('View Details');
  });
});
