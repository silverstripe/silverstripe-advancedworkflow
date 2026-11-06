import {
  STEP_TYPES,
  STAGE_KIND,
  buildStages,
  entryStepId,
  isRejectTransition,
  insertReviewStage,
  insertPublishStage,
  removeStage,
  setSupportEnabled,
} from '../src/lib/workflowStages';

// A transition as the builder payload carries it.
const tx = (id, title, nextStepId, extra = {}) => ({
  id,
  title,
  nextStepId,
  transitionType: 'Active',
  userIds: [],
  groupIds: [],
  ...extra,
});

const step = (id, title, type, transitions = []) => ({
  id,
  title,
  type,
  config: {},
  transitions,
});

// The canonical shape produced by CreateExampleWorkflowTask:
// two review stages sharing one rejection path.
const exampleSteps = () => [
  step('1', 'Assign to Content Reviewers', STEP_TYPES.ASSIGN, [tx('t1', 'Notify', '2')]),
  step('2', 'Notify Content Reviewers', STEP_TYPES.NOTIFICATION, [tx('t2', 'Review', '3')]),
  step('3', 'Content Review', STEP_TYPES.APPROVAL, [tx('t3', 'Approve', '4'), tx('t4', 'Reject', '9')]),
  step('4', 'Assign to Senior Editors', STEP_TYPES.ASSIGN, [tx('t5', 'Notify', '5')]),
  step('5', 'Notify Senior Editors', STEP_TYPES.NOTIFICATION, [tx('t6', 'Sign-off', '6')]),
  step('6', 'Editorial Sign-off', STEP_TYPES.APPROVAL, [tx('t7', 'Approve', '7'), tx('t8', 'Reject', '9')]),
  step('7', 'Assign Initiator (Approved)', STEP_TYPES.ASSIGN, [tx('t9', 'Notify', '8')]),
  step('8', 'Notify Initiator (Approved)', STEP_TYPES.NOTIFICATION, []),
  step('9', 'Reject Changes', STEP_TYPES.CANCEL, [tx('t10', 'Assign', '10')]),
  step('10', 'Assign Initiator (Rejected)', STEP_TYPES.ASSIGN, [tx('t11', 'Notify', '11')]),
  step('11', 'Notify Initiator (Rejected)', STEP_TYPES.NOTIFICATION, []),
];

const allSteps = (m) => [
  ...m.stages.flatMap((s) => [...s.support, s.anchor]),
  ...m.rejection,
  ...m.approvedOutcome,
  ...m.otherSteps,
];

describe('buildStages projection', () => {
  it('returns empty buckets for empty input', () => {
    const empty = { stages: [], rejection: [], approvedOutcome: [], otherSteps: [] };
    expect(buildStages([])).toEqual(empty);
    expect(buildStages(undefined)).toEqual(empty);
  });

  it('reconstructs the example workflow into two review stages + one shared rejection path', () => {
    const m = buildStages(exampleSteps());

    expect(m.stages).toHaveLength(2);
    expect(m.stages.every((s) => s.kind === STAGE_KIND.REVIEW)).toBe(true);
    expect(m.stages[0].anchor.id).toBe('3');
    expect(m.stages[0].support.map((s) => s.id)).toEqual(['1', '2']);
    expect(m.stages[0].approveTargetId).toBe('4');
    expect(m.stages[0].rejectTargetId).toBe('9');
    expect(m.stages[1].anchor.id).toBe('6');

    // Shared reject target appears exactly once across the whole model.
    const rejectIds = m.rejection.map((s) => s.id);
    expect(rejectIds.filter((id) => id === '9')).toHaveLength(1);
    expect(rejectIds.sort()).toEqual(['10', '11', '9']);

    // Trailing steps after the final gate are the "when approved" outcome.
    expect(m.approvedOutcome.map((s) => s.id)).toEqual(['7', '8']);
    expect(m.otherSteps).toEqual([]);
  });

  it('preserves object identity (ids/titles/transitions round-trip untouched)', () => {
    const input = exampleSteps();
    const m = buildStages(input);
    // Same references, so no data can be lost in projection.
    expect(m.stages[0].anchor).toBe(input[2]);
    expect(allSteps(m).sort((a, b) => a.id - b.id)).toEqual(input);
  });

  it('handles an approval with no reject transition', () => {
    const steps = [
      step('a', 'Assign', STEP_TYPES.ASSIGN, [tx('x1', 'Next', 'b')]),
      step('b', 'Approve', STEP_TYPES.APPROVAL, [tx('x2', 'Approve', '')]),
    ];
    const m = buildStages(steps);
    expect(m.stages).toHaveLength(1);
    expect(m.stages[0].rejectTargetId).toBe('');
    expect(m.rejection).toHaveLength(0);
  });

  it('routes setproperty / unpublish / lone orphans into otherSteps', () => {
    const steps = [
      step('p', 'Set a field', STEP_TYPES.SET_PROPERTY, []),
      step('u', 'Unpublish', STEP_TYPES.UNPUBLISH, []),
      step('o', 'Orphan assign', STEP_TYPES.ASSIGN, []),
    ];
    const m = buildStages(steps);
    expect(m.stages).toHaveLength(0);
    expect(m.otherSteps.map((s) => s.id).sort()).toEqual(['o', 'p', 'u']);
    // With no stage, trailing support is not an "approved" outcome.
    expect(m.approvedOutcome).toEqual([]);
  });

  it('routes a support step with multiple outgoing transitions into otherSteps (no data loss)', () => {
    const steps = [
      step('a', 'Fan-out assign', STEP_TYPES.ASSIGN, [tx('f1', 'Next', 'b'), tx('f2', 'Also', 'c')]),
      step('b', 'Approve', STEP_TYPES.APPROVAL, [tx('f3', 'Approve', '')]),
      step('c', 'Approve 2', STEP_TYPES.APPROVAL, [tx('f4', 'Approve', '')]),
    ];
    const m = buildStages(steps);
    const other = m.otherSteps.find((s) => s.id === 'a');
    expect(other).toBeDefined();
    expect(other.transitions).toHaveLength(2);
  });

  it('classifies reject transitions by title', () => {
    expect(isRejectTransition({ title: 'Reject' })).toBe(true);
    expect(isRejectTransition({ title: 'Send back' })).toBe(true);
    expect(isRejectTransition({ title: 'Request changes' })).toBe(true);
    expect(isRejectTransition({ title: 'Approve' })).toBe(false);
  });
});

describe('pure stage operations', () => {
  it('insertReviewStage on empty steps creates a wired assign→notify→approval + cancel cluster', () => {
    const result = insertReviewStage([], {});
    expect(result).toHaveLength(4);
    expect(result.every((s) => s.id.startsWith('new-'))).toBe(true);

    const m = buildStages(result);
    expect(m.stages).toHaveLength(1);
    expect(m.stages[0].support.map((s) => s.type)).toEqual([STEP_TYPES.ASSIGN, STEP_TYPES.NOTIFICATION]);
    expect(m.rejection).toHaveLength(1);
    expect(m.rejection[0].type).toBe(STEP_TYPES.CANCEL);
    expect(m.stages[0].rejectTargetId).toBe(m.rejection[0].id);
  });

  it('insertReviewStage between two stages rewires the previous gate and reuses the shared reject path', () => {
    const before = exampleSteps();
    const result = insertReviewStage(before, { afterStageId: 'stage-3', beforeStageId: 'stage-6' });

    const m = buildStages(result);
    expect(m.stages).toHaveLength(3);

    // No new cancel step — the existing rejection path is reused.
    expect(result.filter((s) => s.type === STEP_TYPES.CANCEL)).toHaveLength(1);

    const newStage = m.stages[1];
    const entry = newStage.support[0];
    // Previous gate (step 3) now flows into the new stage's entry.
    const step3 = result.find((s) => s.id === '3');
    expect(step3.transitions.find((t) => t.title === 'Approve').nextStepId).toBe(entry.id);
    // New gate flows into the old successor (step 4) and rejects to the shared cancel (9).
    expect(newStage.approveTargetId).toBe('4');
    expect(newStage.rejectTargetId).toBe('9');
  });

  it('removeStage drops the whole stage, rewires the previous gate, and keeps the shared reject path', () => {
    const result = removeStage(exampleSteps(), 'stage-6');

    // Steps 4,5,6 gone; shared reject cluster 9,10,11 survives.
    expect(result.find((s) => s.id === '4')).toBeUndefined();
    expect(result.find((s) => s.id === '6')).toBeUndefined();
    expect(result.find((s) => s.id === '9')).toBeDefined();
    expect(result.find((s) => s.id === '10')).toBeDefined();

    // Previous gate (3) rewired to the removed stage's successor (7).
    const step3 = result.find((s) => s.id === '3');
    expect(step3.transitions.find((t) => t.title === 'Approve').nextStepId).toBe('7');

    // No dangling transition points at a removed step.
    const removed = new Set(['4', '5', '6']);
    const dangling = result.flatMap((s) => s.transitions).filter((t) => removed.has(t.nextStepId));
    expect(dangling).toHaveLength(0);
  });

  it('does not mutate the input array or its steps', () => {
    const input = exampleSteps();
    const snapshot = JSON.parse(JSON.stringify(input));
    removeStage(input, 'stage-6');
    insertReviewStage(input, { afterStageId: 'stage-3' });
    expect(input).toEqual(snapshot);
  });

  it('toggling notify off then on round-trips the stage to assign→notify→approval order', () => {
    const off = setSupportEnabled(exampleSteps(), 'stage-3', STEP_TYPES.NOTIFICATION, false);
    let m = buildStages(off);
    expect(m.stages[0].support.map((s) => s.type)).toEqual([STEP_TYPES.ASSIGN]);
    // Assign now flows straight into the gate.
    const assign = off.find((s) => s.id === '1');
    expect(assign.transitions[0].nextStepId).toBe('3');

    const on = setSupportEnabled(off, 'stage-3', STEP_TYPES.NOTIFICATION, true);
    m = buildStages(on);
    expect(m.stages[0].support.map((s) => s.type)).toEqual([STEP_TYPES.ASSIGN, STEP_TYPES.NOTIFICATION]);
  });

  it('insertPublishStage appends a publish step wired from the last gate', () => {
    // Drop the trailing approved-path steps so the last stage is the gate.
    const steps = exampleSteps().filter((s) => !['7', '8'].includes(s.id));
    const result = insertPublishStage(steps, {});
    const m = buildStages(result);

    const publishStage = m.stages.find((s) => s.kind === STAGE_KIND.PUBLISH);
    expect(publishStage).toBeDefined();

    const lastGate = result.find((s) => s.id === '6');
    expect(lastGate.transitions.find((t) => t.title === 'Approve').nextStepId).toBe(publishStage.anchor.id);
  });
});
