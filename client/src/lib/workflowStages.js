// ============================================================
// workflowStages
// Pure, framework-free model that projects the flat
// steps + transitions payload (the shape the builder loads and
// saves) into "stages" for the UI, and provides pure operations
// that add/insert/remove/reorder stages by returning a NEW steps
// array with transitions rewired.
//
// NON-DESTRUCTIVENESS CONTRACT (see the redesign plan):
//  1. Existing step/transition objects are never mutated in place
//     and their DB `id` + original `title` are preserved end to
//     end, because the save reconciler matches transitions by id
//     then by title and re-applies per-transition restrictions.
//  2. The rejection path is a single shared set — several gates
//     may reject to the same Cancel node.
//  3. Anything that does not fit a clean stage falls into
//     `otherSteps` and is left byte-for-byte untouched. No step's
//     `type` is ever changed by these functions.
// ============================================================

export const STEP_TYPES = Object.freeze({
  APPROVAL: 'approval',
  NOTIFICATION: 'notification',
  ASSIGN: 'assign',
  PUBLISH: 'publish',
  UNPUBLISH: 'unpublish',
  SET_PROPERTY: 'setproperty',
  CANCEL: 'cancel',
});

export const STAGE_KIND = Object.freeze({
  REVIEW: 'review',
  PUBLISH: 'publish',
});

// UI-only transition role labels. These are also the persisted
// titles for transitions this module creates; "Reject" must stay
// literally "Reject" so the server's reject/approve title heuristic
// classifies it correctly.
export const TRANSITION_TITLE = Object.freeze({
  NEXT: 'Next',
  APPROVE: 'Approve',
  REJECT: 'Reject',
});

const DEFAULT_TRANSITION_TYPE = 'Active';

// The two support step types that make up the standard cluster
// leading into an approval gate.
const SUPPORT_TYPES = Object.freeze([STEP_TYPES.ASSIGN, STEP_TYPES.NOTIFICATION]);

// Titles applied to auto-created cluster steps.
const DEFAULT_TITLES = Object.freeze({
  [STEP_TYPES.ASSIGN]: 'Assign reviewers',
  [STEP_TYPES.NOTIFICATION]: 'Notify reviewers',
  [STEP_TYPES.APPROVAL]: 'Review & approve',
  [STEP_TYPES.CANCEL]: 'Reject changes',
  [STEP_TYPES.PUBLISH]: 'Publish',
});

// Matches the server's reject/approve title heuristic
// (WorkflowBuilderService::resolveBranchType).
const REJECT_TITLE_RE = /(reject|back|change)/i;

export const isRejectTransition = (transition) => REJECT_TITLE_RE.test((transition && transition.title) || '');

const transitionsOf = (step) => (Array.isArray(step.transitions) ? step.transitions : []);

const isSupportStep = (step) => SUPPORT_TYPES.includes(step.type) && transitionsOf(step).length <= 1;

// Canonical order of support steps within a stage (assign before notify).
const supportOrder = (type) => SUPPORT_TYPES.indexOf(type);

// Locally-unique ids for freshly created steps. `new-` prefixed ids
// tell the save reconciler these are new records (never matched to
// an existing action), so cluster steps are always created fresh
// and never type-converted from an unrelated step.
let idCounter = 0;
const uid = (type) => `new-${type}-${Date.now()}-${idCounter++}`;

const makeTransition = (title, nextStepId) => ({
  title,
  nextStepId,
  transitionType: DEFAULT_TRANSITION_TYPE,
});

const makeStep = (type, title) => ({
  id: uid(type),
  title: title || DEFAULT_TITLES[type] || 'Step',
  type,
  config: {},
  transitions: [],
});

// ------------------------------------------------------------
// Projection: steps -> stages
// ------------------------------------------------------------

/**
 * Collect the set of step ids that belong to the shared rejection
 * path: every step reachable from an approval's reject transition,
 * plus Cancel steps and anything explicitly titled "reject…".
 */
const collectRejectReachable = (steps) => {
  const byId = new Map(steps.map((step) => [step.id, step]));
  const reached = new Set();

  const follow = (id) => {
    if (!id || reached.has(id) || !byId.has(id)) {
      return;
    }
    reached.add(id);
    transitionsOf(byId.get(id)).forEach((t) => follow(t.nextStepId));
  };

  steps.forEach((step) => {
    if (step.type === STEP_TYPES.APPROVAL) {
      transitionsOf(step).filter(isRejectTransition).forEach((t) => follow(t.nextStepId));
    }
  });

  steps.forEach((step) => {
    if (step.type === STEP_TYPES.CANCEL || /reject/i.test(step.title || '')) {
      reached.add(step.id);
    }
  });

  return reached;
};

const approveTransition = (step) => transitionsOf(step).find((t) => !isRejectTransition(t)) || null;
const rejectTransition = (step) => transitionsOf(step).find(isRejectTransition) || null;

const makeReviewStage = (anchor, support) => {
  const approve = approveTransition(anchor);
  const reject = rejectTransition(anchor);

  return {
    id: `stage-${anchor.id}`,
    kind: STAGE_KIND.REVIEW,
    anchor,
    support: [...support],
    approveTargetId: approve ? approve.nextStepId : '',
    rejectTargetId: reject ? reject.nextStepId : '',
  };
};

const makePublishStage = (anchor) => ({
  id: `stage-${anchor.id}`,
  kind: STAGE_KIND.PUBLISH,
  anchor,
  support: [],
  approveTargetId: '',
  rejectTargetId: '',
});

/**
 * Group the happy-path steps (Sort order) into stages. Support
 * steps accumulate until an anchor closes the stage; a Publish
 * anchor is its own outcome stage. Support steps trailing after the
 * last stage are the "when approved" outcome (e.g. notify the author
 * that their content passed); with no stage at all they are just
 * loose steps. Anything that is neither anchor nor clean support
 * falls into otherSteps.
 */
const groupFlow = (flowSteps) => {
  const stages = [];
  const otherSteps = [];
  let support = [];

  const flushSupportToOther = () => {
    support.forEach((step) => otherSteps.push(step));
    support = [];
  };

  flowSteps.forEach((step) => {
    if (step.type === STEP_TYPES.APPROVAL) {
      stages.push(makeReviewStage(step, support));
      support = [];
    } else if (step.type === STEP_TYPES.PUBLISH) {
      flushSupportToOther();
      stages.push(makePublishStage(step));
    } else if (isSupportStep(step)) {
      support.push(step);
    } else {
      otherSteps.push(step);
    }
  });

  // Trailing support belongs to the approved outcome only when it
  // follows at least one stage; otherwise it is just loose steps.
  const approvedOutcome = stages.length > 0 ? support : [];
  if (stages.length === 0) {
    flushSupportToOther();
  }

  return { stages, otherSteps, approvedOutcome };
};

/**
 * Project a flat steps array into the stage read-model. Returns the
 * SAME step objects (never copies), so ids/titles/transitions round
 * trip untouched.
 *
 * @param {Array} steps
 * @returns {{stages: Array, rejection: Array, approvedOutcome: Array, otherSteps: Array}}
 */
export const buildStages = (steps) => {
  const list = Array.isArray(steps) ? steps : [];

  if (list.length === 0) {
    return { stages: [], rejection: [], approvedOutcome: [], otherSteps: [] };
  }

  const rejectIds = collectRejectReachable(list);
  const flowSteps = list.filter((step) => !rejectIds.has(step.id));
  const rejection = list.filter((step) => rejectIds.has(step.id));

  const { stages, otherSteps, approvedOutcome } = groupFlow(flowSteps);
  return { stages, rejection, approvedOutcome, otherSteps };
};

/** The step a stage is entered through (its first support step, or its anchor). */
export const entryStepId = (stage) => {
  if (stage.kind === STAGE_KIND.REVIEW && stage.support.length > 0) {
    return stage.support[0].id;
  }
  return stage.anchor.id;
};

// ------------------------------------------------------------
// Pure operations: steps -> new steps
// ------------------------------------------------------------

// Deep-clone a step so edits never touch the caller's objects.
const cloneStep = (step) => ({
  ...step,
  config: { ...(step.config || {}) },
  transitions: transitionsOf(step).map((t) => ({ ...t })),
});

const cloneSteps = (steps) => steps.map(cloneStep);

const indexOfId = (steps, id) => steps.findIndex((step) => step.id === id);

/** Set the non-reject (forward/approve) transition target on a step, preserving its id/title. */
const setForwardTarget = (step, nextStepId) => {
  const transitions = transitionsOf(step);
  const forward = transitions.find((t) => !isRejectTransition(t));

  if (!nextStepId) {
    step.transitions = transitions.filter((t) => isRejectTransition(t));
    return;
  }

  if (forward) {
    forward.nextStepId = nextStepId;
    return;
  }

  const title = step.type === STEP_TYPES.APPROVAL ? TRANSITION_TITLE.APPROVE : TRANSITION_TITLE.NEXT;
  step.transitions = [...transitions, makeTransition(title, nextStepId)];
};

/** Find the shared rejection entry (an existing approval's reject target), if any. */
const findRejectTargetId = (steps) => {
  for (const step of steps) {
    if (step.type !== STEP_TYPES.APPROVAL) {
      continue;
    }
    const reject = rejectTransition(step);
    if (reject && reject.nextStepId) {
      return reject.nextStepId;
    }
  }
  return '';
};

/**
 * Build a fresh review-stage cluster (Assign -> Notify -> Approval),
 * wired internally. When there is no existing rejection path a
 * Cancel step is created too and the approval's Reject points at it.
 *
 * @returns {{cluster: Array, cancel: (Object|null), entryId: string, approvalStep: Object}}
 */
const buildReviewCluster = (rejectTargetId) => {
  const assign = makeStep(STEP_TYPES.ASSIGN);
  const notify = makeStep(STEP_TYPES.NOTIFICATION);
  const approval = makeStep(STEP_TYPES.APPROVAL);

  assign.transitions = [makeTransition(TRANSITION_TITLE.NEXT, notify.id)];
  notify.transitions = [makeTransition(TRANSITION_TITLE.NEXT, approval.id)];

  let cancel = null;
  let rejectId = rejectTargetId;

  if (!rejectId) {
    cancel = makeStep(STEP_TYPES.CANCEL);
    rejectId = cancel.id;
  }

  approval.transitions = [makeTransition(TRANSITION_TITLE.REJECT, rejectId)];

  return { cluster: [assign, notify, approval], cancel, entryId: assign.id, approvalStep: approval };
};

/**
 * Insert a new review stage. `afterStageId` / `beforeStageId` name
 * the neighbouring stages (from buildStages); pass null for the
 * ends. The previous stage's forward transition is rewired into the
 * new cluster, and the new gate's Approve transition points at the
 * step the previous stage used to flow into.
 *
 * @param {Array} steps
 * @param {{afterStageId?: string, beforeStageId?: string}} at
 * @returns {Array} new steps
 */
export const insertReviewStage = (steps, at = {}) => {
  const next = cloneSteps(steps);
  const model = buildStages(next);
  const findStage = (id) => model.stages.find((stage) => stage.id === id) || null;

  const afterStage = at.afterStageId ? findStage(at.afterStageId) : null;
  const beforeStage = at.beforeStageId ? findStage(at.beforeStageId) : null;

  const { cluster, cancel, entryId, approvalStep } = buildReviewCluster(findRejectTargetId(next));

  // Where the previous stage currently flows (so the new gate can take it over).
  const successorId = beforeStage
    ? entryStepId(beforeStage)
    : (afterStage ? afterStage.approveTargetId : '');

  setForwardTarget(approvalStep, successorId);

  if (afterStage) {
    const prevAnchor = next[indexOfId(next, afterStage.anchor.id)];
    setForwardTarget(prevAnchor, entryId);
  }

  const insertAt = beforeStage
    ? indexOfId(next, entryStepId(beforeStage))
    : rejectionInsertIndex(next);

  next.splice(insertAt < 0 ? next.length : insertAt, 0, ...cluster);

  if (cancel) {
    next.push(cancel);
  }

  return next;
};

/** Index just before the first rejection step, so appended happy-path steps precede the reject path. */
const rejectionInsertIndex = (steps) => {
  const rejectIds = collectRejectReachable(steps);
  const idx = steps.findIndex((step) => rejectIds.has(step.id));
  return idx < 0 ? steps.length : idx;
};

/**
 * Insert a Publish outcome stage. Any approval stage that currently
 * flows to nothing (or to the old successor) is rewired so its
 * Approve transition points at the new Publish step.
 *
 * @param {Array} steps
 * @param {{afterStageId?: string}} at
 * @returns {Array} new steps
 */
export const insertPublishStage = (steps, at = {}) => {
  const next = cloneSteps(steps);
  const model = buildStages(next);
  const afterStage = at.afterStageId
    ? model.stages.find((stage) => stage.id === at.afterStageId)
    : model.stages[model.stages.length - 1];

  const publish = makeStep(STEP_TYPES.PUBLISH);
  next.splice(rejectionInsertIndex(next), 0, publish);

  if (afterStage && afterStage.kind === STAGE_KIND.REVIEW) {
    setForwardTarget(next[indexOfId(next, afterStage.anchor.id)], publish.id);
  }

  return next;
};

/**
 * Remove a whole stage (its support steps + anchor for a review
 * stage; the anchor for a publish stage). The previous stage's
 * forward transition is rewired to the removed stage's successor,
 * and any transition pointing into a removed step is dropped. The
 * shared rejection path is never removed here.
 *
 * @param {Array} steps
 * @param {string} stageId
 * @returns {Array} new steps
 */
export const removeStage = (steps, stageId) => {
  const next = cloneSteps(steps);
  const model = buildStages(next);
  const stageIndex = model.stages.findIndex((stage) => stage.id === stageId);

  if (stageIndex < 0) {
    return next;
  }

  const stage = model.stages[stageIndex];
  const removedIds = new Set([...stage.support.map((s) => s.id), stage.anchor.id]);
  const successorId = stage.approveTargetId;

  const prevStage = model.stages[stageIndex - 1];
  if (prevStage) {
    setForwardTarget(next[indexOfId(next, prevStage.anchor.id)], successorId);
  }

  return next
    .filter((step) => !removedIds.has(step.id))
    .map((step) => ({
      ...step,
      transitions: transitionsOf(step).filter((t) => !removedIds.has(t.nextStepId)),
    }));
};

/**
 * Toggle a support step (assign/notify) on or off within a review
 * stage. Turning it off removes that step and rewires the chain;
 * turning it on inserts a fresh support step at the front of the
 * stage and points it at what the entry used to be.
 *
 * @param {Array} steps
 * @param {string} stageId
 * @param {string} supportType STEP_TYPES.ASSIGN | STEP_TYPES.NOTIFICATION
 * @param {boolean} enabled
 * @returns {Array} new steps
 */
export const setSupportEnabled = (steps, stageId, supportType, enabled) => {
  const next = cloneSteps(steps);
  const model = buildStages(next);
  const stage = model.stages.find((s) => s.id === stageId);

  if (!stage || stage.kind !== STAGE_KIND.REVIEW) {
    return next;
  }

  const existing = stage.support.find((s) => s.type === supportType);

  if (enabled && !existing) {
    return addSupportStep(next, stage, supportType);
  }

  if (!enabled && existing) {
    return removeSupportStep(next, existing);
  }

  return next;
};

/**
 * Insert a fresh support step into a stage in canonical order
 * (assign before notify, both before the gate), chained to the step
 * that should follow it.
 */
const addSupportStep = (steps, stage, supportType) => {
  const laterSupport = stage.support.find((s) => supportOrder(s.type) > supportOrder(supportType));
  const successorId = laterSupport ? laterSupport.id : stage.anchor.id;

  const step = makeStep(supportType);
  step.transitions = [makeTransition(TRANSITION_TITLE.NEXT, successorId)];

  const insertAt = indexOfId(steps, successorId);
  const withStep = [...steps];
  withStep.splice(insertAt < 0 ? withStep.length : insertAt, 0, step);

  return rewireInboundTo(withStep, successorId, step.id, [step.id]);
};

/** Remove a single support step from a stage and heal the chain around it. */
const removeSupportStep = (steps, supportStep) => {
  const successorId = (approveTransition(supportStep) || {}).nextStepId || '';

  return rewireInboundTo(steps, supportStep.id, successorId, [])
    .filter((step) => step.id !== supportStep.id)
    .map((step) => ({
      ...step,
      transitions: transitionsOf(step).filter((t) => t.nextStepId !== supportStep.id),
    }));
};

/**
 * Point every forward transition that currently targets `fromId` at
 * `toId` instead, skipping steps in `exceptIds`. Used to splice a
 * step into (or out of) a linear chain without touching reject edges
 * or transition ids.
 */
const rewireInboundTo = (steps, fromId, toId, exceptIds) => steps.map((step) => {
  if (exceptIds.includes(step.id)) {
    return step;
  }
  return {
    ...step,
    transitions: transitionsOf(step).map((t) => (
      t.nextStepId === fromId && !isRejectTransition(t) ? { ...t, nextStepId: toId } : t
    )),
  };
});
