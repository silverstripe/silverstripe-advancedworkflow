import React, { useEffect, useState, useCallback, useId } from 'react';
import i18n from 'i18n';
import Icon from './Icon';
import FieldLabel from './FieldLabel';
import MultiSelect from './MultiSelect';
import StageCard from './StageCard';
import {
  STEP_TYPES,
  STAGE_KIND,
  buildStages,
  isRejectTransition,
  insertReviewStage,
  insertPublishStage,
  removeStage as removeStageOp,
  setSupportEnabled,
} from '../lib/workflowStages';
import { configFieldsFor, transitionTypes, metaFor } from '../lib/stepMeta';
import onActivateKey from '../lib/keyboard';
import { ENDPOINT, getJson, postJson } from '../lib/api';
import { LOAD_FAILURE, classifyLoadFailure } from '../lib/workflowResponse';

// Whether the definition's steps have been read from the server. The builder
// must not render its empty state, or allow saving, until they have: saving
// an unloaded builder would delete the definition's steps.
export const LOAD_STATE = Object.freeze({
  LOADING: 'loading',
  READY: 'ready',
  FAILED: 'failed',
});

const loadFailureMessage = (failure) => ({
  [LOAD_FAILURE.FORBIDDEN]: i18n._t(
    'WorkflowBuilder.LOAD_FORBIDDEN',
    'You do not have permission to edit this workflow.'
  ),
  [LOAD_FAILURE.UNAVAILABLE]: i18n._t('WorkflowBuilder.LOAD_UNAVAILABLE', 'The workflow could not be loaded.'),
  [LOAD_FAILURE.NETWORK]: i18n._t(
    'WorkflowBuilder.LOAD_NETWORK',
    'The workflow could not be loaded. Check your connection and try again.'
  ),
}[failure]);

// Advanced standalone steps offered in the "Other steps" area — these
// sit outside the stage model and are wired manually.
const ADVANCED_STEP_TYPES = Object.freeze([STEP_TYPES.SET_PROPERTY, STEP_TYPES.UNPUBLISH]);

export default function WorkflowBuilder({ definitionId }) {
  // Workflow metadata (title, description, assigned groups/users, …) is
  // owned by the CMS Main tab; the builder only edits the step/flow
  // structure, so saving here never clobbers Main-tab fields.
  const [meta, setMeta] = useState({ groups: {}, users: {} });
  const [userLabels, setUserLabels] = useState({});
  const [steps, setSteps] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const idBase = useId();
  const fieldId = (...parts) => [idBase, ...parts].join('-');
  const [isSaving, setIsSaving] = useState(false);
  const [saveMessage, setSaveMessage] = useState(null);
  const [loadState, setLoadState] = useState(definitionId ? LOAD_STATE.LOADING : LOAD_STATE.READY);
  const [loadError, setLoadError] = useState(null);

  const loadMeta = useCallback(() => {
    getJson(ENDPOINT.WORKFLOW_META)
      .then(({ ok, data }) => {
        if (ok) setMeta(data);
      })
      .catch(() => {});
  }, []);

  // Server-side user search (users aren't preloaded — see workflowMeta).
  const searchUsers = useCallback(
    (term) => getJson(ENDPOINT.SEARCH_USERS, { term })
      .then(({ ok, data }) => (ok && Array.isArray(data) ? data : []))
      .catch(() => []),
    []
  );

  const loadDefinition = useCallback(() => {
    if (!definitionId) return;

    setLoadState(LOAD_STATE.LOADING);
    setLoadError(null);
    getJson(ENDPOINT.LOAD_DEFINITION, { ID: definitionId })
      .then(({ ok, status, data }) => {
        if (!ok || !data || data.error) {
          setLoadError(loadFailureMessage(classifyLoadFailure(status)));
          setLoadState(LOAD_STATE.FAILED);
          return;
        }
        setUserLabels(data.userLabels || {});
        setSteps(Array.isArray(data.steps) ? data.steps : []);
        setLoadState(LOAD_STATE.READY);
      })
      .catch(() => {
        setLoadError(loadFailureMessage(classifyLoadFailure(null)));
        setLoadState(LOAD_STATE.FAILED);
      });
  }, [definitionId]);

  useEffect(() => {
    loadMeta();
    loadDefinition();
  }, [loadMeta, loadDefinition]);

  const model = buildStages(steps);

  // The set of step ids managed by a stage — those are edited through the
  // stage cards; everything else (rejection path, other steps) keeps the
  // raw transition editor.
  const managedIds = new Set(
    model.stages.flatMap((stage) => [...stage.support.map((s) => s.id), stage.anchor.id])
  );

  const updateStep = (id, changes) => {
    setSteps((current) => current.map((s) => (s.id === id ? { ...s, ...changes } : s)));
  };

  const updateConfig = (id, key, value) => {
    setSteps((current) => current.map((s) => (
      s.id === id ? { ...s, config: { ...(s.config || {}), [key]: value } } : s
    )));
  };

  // Drop a step whose wiring is not stage-managed (rejection/other steps).
  const removeRawStep = (stepId) => {
    setSteps((current) => current
      .filter((s) => s.id !== stepId)
      .map((s) => ({
        ...s,
        transitions: (s.transitions || []).filter((t) => t.nextStepId !== stepId),
      })));
    if (selectedId === stepId) setSelectedId(null);
  };

  // ---- Stage operations (delegated to the pure model) ----

  const applyOp = (nextSteps) => setSteps(nextSteps);

  const addReviewStageAtEnd = () => {
    const last = model.stages[model.stages.length - 1];
    applyOp(insertReviewStage(steps, { afterStageId: last ? last.id : undefined }));
  };

  const insertReviewBetween = (afterStageId, beforeStageId) => {
    applyOp(insertReviewStage(steps, { afterStageId, beforeStageId }));
  };

  const addPublishStage = () => applyOp(insertPublishStage(steps, {}));

  const removeStage = (stageId) => {
    const stage = model.stages.find((s) => s.id === stageId);
    applyOp(removeStageOp(steps, stageId));

    const removedIds = stage ? [...stage.support.map((s) => s.id), stage.anchor.id] : [];
    if (removedIds.includes(selectedId)) setSelectedId(null);
  };

  const addSupport = (stageId, type) => applyOp(setSupportEnabled(steps, stageId, type, true));
  const removeSupport = (stageId, type) => applyOp(setSupportEnabled(steps, stageId, type, false));

  const addAdvancedStep = (type) => {
    const id = `new-${type}-${Date.now()}`;
    setSteps((current) => [...current, {
      id, title: metaFor(type).label, type, config: {}, transitions: [],
    }]);
    setSelectedId(id);
  };

  const stepTitle = (id) => {
    const found = steps.find((s) => s.id === id);
    return found ? found.title : '—';
  };

  const saveWorkflow = async () => {
    setIsSaving(true);
    setSaveMessage(null);

    try {
      const response = await postJson(ENDPOINT.SAVE_WORKFLOW, {
        id: definitionId || null,
        steps: steps.map((s) => ({
          id: s.id,
          title: s.title,
          type: s.type,
          config: s.config || {},
          transitions: s.transitions || [],
        })),
      });

      if (!response.ok) throw new Error('Save failed');
      setSaveMessage({ ok: true, text: i18n._t('WorkflowBuilder.SAVED', 'Saved') });
      setTimeout(() => setSaveMessage(null), 3000);
    } catch {
      setSaveMessage({ ok: false, text: i18n._t('WorkflowBuilder.SAVE_FAILED', 'Failed to save') });
    } finally {
      setIsSaving(false);
    }
  };

  const selectedStep = steps.find((s) => s.id === selectedId);
  const selectedConfig = selectedStep ? configFieldsFor(selectedStep.type) : null;
  const selectedIsManaged = selectedStep ? managedIds.has(selectedStep.id) : false;

  // ---- Right-panel config editors (shared with raw steps) ----

  const renderMultiSelect = (source, selectedIds, onChange, inputId) => {
    if (source === 'users') {
      return (
        <MultiSelect
          inputId={inputId}
          value={selectedIds}
          onChange={onChange}
          onSearch={searchUsers}
          labels={userLabels}
          placeholder={i18n._t('WorkflowBuilder.SEARCH_USERS', 'Search users…')}
        />
      );
    }

    return (
      <MultiSelect
        inputId={inputId}
        options={meta.groups || {}}
        value={selectedIds}
        onChange={onChange}
        placeholder={i18n._t('WorkflowBuilder.SEARCH_GROUPS', 'Search groups…')}
      />
    );
  };

  const renderConfigField = (step, field) => {
    const value = (step.config || {})[field.key];
    const id = fieldId('config', field.key);

    if (field.input === 'multiselect') {
      return renderMultiSelect(field.source, value, (ids) => updateConfig(step.id, field.key, ids), id);
    }

    if (field.input === 'select') {
      return (
        <select id={id} value={value ?? ''} onChange={(e) => updateConfig(step.id, field.key, e.target.value)}>
          {field.options.map((opt) => (
            <option key={opt.value} value={opt.value}>{opt.label}</option>
          ))}
        </select>
      );
    }

    if (field.input === 'checkbox') {
      return (
        <label className="wf-builder__checkbox" htmlFor={id}>
          <input
            id={id}
            type="checkbox"
            checked={Boolean(value)}
            onChange={(e) => updateConfig(step.id, field.key, e.target.checked)}
          />
          {field.label}
        </label>
      );
    }

    if (field.input === 'textarea') {
      return (
        <textarea
          id={id}
          value={value ?? ''}
          onChange={(e) => updateConfig(step.id, field.key, e.target.value)}
          rows={4}
        />
      );
    }

    if (field.input === 'number') {
      return (
        <input
          id={id}
          type="number"
          min="0"
          value={value ?? 0}
          onChange={(e) => updateConfig(step.id, field.key, parseInt(e.target.value, 10) || 0)}
        />
      );
    }

    return (
      <input
        id={id}
        type="text"
        value={value ?? ''}
        onChange={(e) => updateConfig(step.id, field.key, e.target.value)}
      />
    );
  };

  // ---- Raw transition editor (rejection / other steps only) ----

  const otherStepOptions = (excludeId) => steps
    .filter((s) => s.id !== excludeId)
    .map((s) => <option key={s.id} value={s.id}>{s.title}</option>);

  const transitionIndexForRole = (step, list, role) => {
    if (step.type !== STEP_TYPES.APPROVAL) {
      return list.length ? 0 : -1;
    }
    return role === 'reject'
      ? list.findIndex(isRejectTransition)
      : list.findIndex((t) => !isRejectTransition(t));
  };

  const titleForRole = (step, role) => {
    if (step.type !== STEP_TYPES.APPROVAL) return 'Next';
    return role === 'reject' ? 'Reject' : 'Approve';
  };

  const getTransition = (step, role) => {
    const list = Array.isArray(step.transitions) ? step.transitions : [];
    const index = transitionIndexForRole(step, list, role);
    return index >= 0 ? list[index] : null;
  };

  const getTransitionTarget = (step, role) => {
    const transition = getTransition(step, role);
    return transition ? transition.nextStepId : '';
  };

  const setTransitionTarget = (step, role, nextStepId) => {
    const list = Array.isArray(step.transitions) ? [...step.transitions] : [];
    const index = transitionIndexForRole(step, list, role);

    if (!nextStepId) {
      if (index >= 0) list.splice(index, 1);
    } else if (index >= 0) {
      list[index] = { ...list[index], title: titleForRole(step, role), nextStepId };
    } else {
      list.push({ title: titleForRole(step, role), nextStepId, transitionType: 'Active' });
    }

    updateStep(step.id, { transitions: list });
  };

  const patchTransition = (step, role, patch) => {
    const list = Array.isArray(step.transitions) ? [...step.transitions] : [];
    const index = transitionIndexForRole(step, list, role);
    if (index < 0) return;
    list[index] = { ...list[index], ...patch };
    updateStep(step.id, { transitions: list });
  };

  const renderTransitionAdvanced = (step, role) => {
    const transition = getTransition(step, role);
    if (!transition) return null;

    return (
      <details className="wf-builder__transition-advanced">
        <summary>{i18n._t('WorkflowBuilder.TRANSITION_ADVANCED', 'Advanced: type & permissions')}</summary>
        <div className="wf-builder__edit-row">
          <FieldLabel htmlFor={fieldId(role, 'type')}>{i18n._t('WorkflowBuilder.TRANSITION_TYPE', 'Transition type')}</FieldLabel>
          <select
            id={fieldId(role, 'type')}
            value={transition.transitionType || 'Active'}
            onChange={(e) => patchTransition(step, role, { transitionType: e.target.value })}
          >
            {transitionTypes().map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
          </select>
        </div>
        <div className="wf-builder__edit-row">
          <FieldLabel htmlFor={fieldId(role, 'groups')}>{i18n._t('WorkflowBuilder.RESTRICT_GROUPS', 'Restrict to groups')}</FieldLabel>
          <p className="wf-builder__field-help">
            {i18n._t('WorkflowBuilder.RESTRICT_GROUPS_HELP', 'Leave empty to allow anyone assigned to the step.')}
          </p>
          {renderMultiSelect(
            'groups',
            transition.groupIds,
            (ids) => patchTransition(step, role, { groupIds: ids }),
            fieldId(role, 'groups')
          )}
        </div>
        <div className="wf-builder__edit-row">
          <FieldLabel htmlFor={fieldId(role, 'users')}>{i18n._t('WorkflowBuilder.RESTRICT_USERS', 'Restrict to users')}</FieldLabel>
          {renderMultiSelect(
            'users',
            transition.userIds,
            (ids) => patchTransition(step, role, { userIds: ids }),
            fieldId(role, 'users')
          )}
        </div>
      </details>
    );
  };

  const renderTransitionRow = (step, role, labelNode, emptyOption) => (
    <div className="wf-builder__edit-row wf-builder__edit-row--branch">
      <FieldLabel htmlFor={fieldId(role, 'target')}>{labelNode}</FieldLabel>
      <select
        id={fieldId(role, 'target')}
        value={getTransitionTarget(step, role)}
        onChange={(e) => setTransitionTarget(step, role, e.target.value)}
      >
        <option value="">{emptyOption}</option>
        {otherStepOptions(step.id)}
      </select>
      {renderTransitionAdvanced(step, role)}
    </div>
  );

  const renderRawTransitions = (step) => {
    if (step.type === STEP_TYPES.CANCEL) {
      return (
        <p className="wf-builder__field-help">
          {i18n._t('WorkflowBuilder.CANCEL_ENDS_WORKFLOW', 'This step ends the workflow as rejected.')}
        </p>
      );
    }

    if (step.type === STEP_TYPES.APPROVAL) {
      return (
        <>
          {renderTransitionRow(
            step,
            'approve',
            <><Icon name="check" /> {i18n._t('WorkflowBuilder.IF_APPROVED_GO_TO', 'If approved, go to:')}</>,
            i18n._t('WorkflowBuilder.NEXT_STEP_IN_ORDER', 'Next step in order')
          )}
          {renderTransitionRow(
            step,
            'reject',
            <><Icon name="cross" /> {i18n._t('WorkflowBuilder.IF_REJECTED_GO_TO', 'If rejected, go to:')}</>,
            i18n._t('WorkflowBuilder.END_WORKFLOW_REJECT', 'End workflow (reject)')
          )}
        </>
      );
    }

    return renderTransitionRow(
      step,
      'next',
      <><Icon name="arrow-down" /> {i18n._t('WorkflowBuilder.THEN_GO_TO', 'Then go to:')}</>,
      i18n._t('WorkflowBuilder.END_WORKFLOW', 'End workflow')
    );
  };

  // ---- Left column pieces ----

  const renderInsertPoint = (afterStageId, beforeStageId, key) => (
    <div className="wf-builder__insert" key={key}>
      <button
        type="button"
        className="wf-builder__insert-btn"
        onClick={() => insertReviewBetween(afterStageId, beforeStageId)}
      >
        <Icon name="plus" /> {i18n._t('WorkflowBuilder.INSERT_STAGE', 'Insert a review stage here')}
      </button>
    </div>
  );

  const renderRawStepRow = (step) => {
    const stepMeta = metaFor(step.type);
    return (
      <div
        key={step.id}
        className={`wf-builder__raw-step ${selectedId === step.id ? 'wf-builder__raw-step--selected' : ''}`}
        onClick={() => setSelectedId(step.id)}
        onKeyDown={onActivateKey(() => setSelectedId(step.id))}
        role="button"
        tabIndex={0}
        aria-pressed={selectedId === step.id}
      >
        <span className="wf-builder__substep-icon"><Icon name={stepMeta.icon} /></span>
        <div className="wf-builder__substep-info">
          <span className="wf-builder__substep-title">{step.title}</span>
          <span className="wf-builder__substep-role">{stepMeta.label}</span>
        </div>
      </div>
    );
  };

  const hasAnything = steps.length > 0;
  const hasPublishStage = model.stages.some((s) => s.kind === STAGE_KIND.PUBLISH);

  const renderStages = () => (
    <>
      {renderInsertPoint(undefined, model.stages[0] && model.stages[0].id, 'insert-top')}
      {model.stages.map((stage, index) => (
        <React.Fragment key={stage.id}>
          <StageCard
            stage={stage}
            number={index + 1}
            selectedId={selectedId}
            stepTitle={stepTitle}
            onSelectStep={setSelectedId}
            onAddSupport={addSupport}
            onRemoveSupport={removeSupport}
            onRemoveStage={removeStage}
          />
          {index < model.stages.length - 1
            && renderInsertPoint(stage.id, model.stages[index + 1].id, `insert-${stage.id}`)}
        </React.Fragment>
      ))}
    </>
  );

  return (
    <div className="wf-builder">
      <div className="wf-builder__header">
        <div>
          <span className="wf-builder__heading">{i18n._t('WorkflowBuilder.HEADING', 'Visual Workflow Builder')}</span>
          <p className="wf-builder__intro">
            {i18n._t(
              'WorkflowBuilder.INTRO',
              'A workflow is a series of review stages. Each stage assigns reviewers, notifies them, '
                + 'then waits for them to approve or reject. Approved content moves to the next stage; '
                + 'rejected content follows the rejection path. Add a stage to begin — the standard steps '
                + 'are created for you.'
            )}
          </p>
        </div>
        <div className="wf-builder__actions">
          {saveMessage && (
            <span className={`wf-builder__msg ${saveMessage.ok ? '' : 'wf-builder__msg--error'}`}>
              <Icon name={saveMessage.ok ? 'check' : 'cross'} /> {saveMessage.text}
            </span>
          )}
          <button
            className="wf-builder__btn wf-builder__btn--save"
            onClick={saveWorkflow}
            disabled={isSaving || loadState !== LOAD_STATE.READY}
            type="button"
          >
            {isSaving
              ? i18n._t('WorkflowBuilder.SAVING', 'Saving…')
              : i18n._t('WorkflowBuilder.SAVE', 'Save Workflow')}
          </button>
        </div>
      </div>

      {loadState === LOAD_STATE.LOADING && (
        <div className="wf-builder__status" role="status" aria-live="polite">
          {i18n._t('WorkflowBuilder.LOADING', 'Loading workflow…')}
        </div>
      )}

      {loadState === LOAD_STATE.FAILED && (
        <div className="wf-builder__status wf-builder__status--error" role="alert">
          <span>{loadError}</span>
          <button type="button" className="wf-builder__btn" onClick={loadDefinition}>
            {i18n._t('WorkflowBuilder.TRY_AGAIN', 'Try again')}
          </button>
        </div>
      )}

      {loadState === LOAD_STATE.READY && (
        <div className="wf-builder__columns">
          <div className="wf-builder__flow">
            {!hasAnything && (
              <div className="wf-builder__flow-empty">
                <p className="wf-builder__empty-title">{i18n._t('WorkflowBuilder.NO_STAGES', 'No stages yet')}</p>
                <p className="wf-builder__empty-desc">
                  {i18n._t(
                    'WorkflowBuilder.NO_STAGES_HELP',
                    'Start by adding a review stage. We\'ll create the standard steps — assign reviewers, '
                      + 'notify them, and wait for approval — and a rejection path, all wired together. '
                      + 'You can fine-tune each step afterwards.'
                  )}
                </p>
                <button type="button" className="wf-builder__btn wf-builder__btn--primary" onClick={addReviewStageAtEnd}>
                  <Icon name="plus" /> {i18n._t('WorkflowBuilder.ADD_FIRST_STAGE', 'Add your first review stage')}
                </button>
              </div>
            )}

            {model.stages.length > 0 && renderStages()}

            {hasAnything && (
              <div className="wf-builder__flow-actions">
                <button type="button" className="wf-builder__btn wf-builder__btn--primary" onClick={addReviewStageAtEnd}>
                  <Icon name="plus" /> {i18n._t('WorkflowBuilder.ADD_STAGE', 'Add a review stage')}
                </button>
                {!hasPublishStage && (
                  <button type="button" className="wf-builder__btn" onClick={addPublishStage}>
                    <Icon name="publish" /> {i18n._t('WorkflowBuilder.ADD_PUBLISH_STAGE', 'Add “publish when approved”')}
                  </button>
                )}
              </div>
            )}

            {model.approvedOutcome.length > 0 && (
              <div className="wf-builder__section wf-builder__section--approved">
                <div className="wf-builder__section-label">{i18n._t('WorkflowBuilder.WHEN_APPROVED', 'When approved')}</div>
                <p className="wf-builder__section-help">
                  {i18n._t(
                    'WorkflowBuilder.WHEN_APPROVED_HELP',
                    'These steps run after the final approval — for example, letting the author know '
                      + 'their content passed. Click one to edit it.'
                  )}
                </p>
                {model.approvedOutcome.map(renderRawStepRow)}
              </div>
            )}

            {model.rejection.length > 0 && (
              <div className="wf-builder__section wf-builder__section--reject">
                <div className="wf-builder__section-label">{i18n._t('WorkflowBuilder.REJECTION_PATH', 'Rejection path')}</div>
                <p className="wf-builder__section-help">
                  {i18n._t(
                    'WorkflowBuilder.REJECTION_PATH_HELP',
                    'These steps run only when a reviewer rejects the content. Every stage\'s '
                      + '“reject” outcome leads here.'
                  )}
                </p>
                {model.rejection.map(renderRawStepRow)}
              </div>
            )}

            {model.otherSteps.length > 0 && (
              <div className="wf-builder__section wf-builder__section--other">
                <div className="wf-builder__section-label">{i18n._t('WorkflowBuilder.OTHER_STEPS', 'Other steps')}</div>
                <p className="wf-builder__section-help">
                  {i18n._t(
                    'WorkflowBuilder.OTHER_STEPS_HELP',
                    'Steps that aren\'t part of a standard review stage. Click one to edit it and '
                      + 'set where it leads.'
                  )}
                </p>
                {model.otherSteps.map(renderRawStepRow)}
              </div>
            )}

            {hasAnything && (
              <details className="wf-builder__advanced-add">
                <summary>{i18n._t('WorkflowBuilder.ADD_ADVANCED_STEP', 'Add an advanced step')}</summary>
                {ADVANCED_STEP_TYPES.map((type) => {
                  const stepMeta = metaFor(type);
                  return (
                    <button
                      key={type}
                      type="button"
                      className="wf-builder__addon-chip wf-builder__addon-chip--advanced"
                      title={stepMeta.description}
                      onClick={() => addAdvancedStep(type)}
                    >
                      <Icon name="plus" /> {stepMeta.label}
                    </button>
                  );
                })}
              </details>
            )}
          </div>

          <div className="wf-builder__content">
            {selectedStep ? (
              <div className="wf-builder__edit">
                <div className="wf-builder__edit-header">
                  <span className="wf-builder__edit-heading">{selectedStep.title}</span>
                  <span className="wf-builder__edit-badge">
                    <Icon name={metaFor(selectedStep.type).icon} /> {metaFor(selectedStep.type).label}
                  </span>
                </div>

                <p className="wf-builder__edit-desc">{metaFor(selectedStep.type).description}</p>

                <div className="wf-builder__edit-row">
                  <FieldLabel htmlFor={fieldId('title')}>{i18n._t('WorkflowBuilder.STEP_NAME', 'Step name')}</FieldLabel>
                  <input
                    id={fieldId('title')}
                    value={selectedStep.title}
                    onChange={(e) => updateStep(selectedStep.id, { title: e.target.value })}
                  />
                </div>

                {selectedConfig && selectedConfig.length > 0 && (
                  <>
                    <div className="wf-builder__edit-divider" />
                    {selectedConfig.map((field) => (
                      <div className="wf-builder__edit-row" key={field.key}>
                        {field.input !== 'checkbox' && (
                          <FieldLabel htmlFor={fieldId('config', field.key)}>{field.label}</FieldLabel>
                        )}
                        {field.help && <p className="wf-builder__field-help">{field.help}</p>}
                        {renderConfigField(selectedStep, field)}
                      </div>
                    ))}
                  </>
                )}

                {selectedIsManaged ? (
                  <>
                    <div className="wf-builder__edit-divider" />
                    <p className="wf-builder__field-help">
                      <Icon name="circle" /> {i18n._t(
                        'WorkflowBuilder.MANAGED_STEP_HELP',
                        'This step is part of a review stage — the builder wires it up automatically. '
                          + 'Use the stage card on the left to add or remove its steps.'
                      )}
                    </p>
                  </>
                ) : (
                  <>
                    <div className="wf-builder__edit-divider" />
                    <p className="wf-builder__edit-label">{i18n._t('WorkflowBuilder.AFTER_THIS_STEP', 'What happens after this step?')}</p>
                    {renderRawTransitions(selectedStep)}

                    <div className="wf-builder__edit-divider" />
                    <button
                      className="wf-builder__btn wf-builder__btn--delete"
                      onClick={() => removeRawStep(selectedStep.id)}
                      type="button"
                    >
                      {i18n._t('WorkflowBuilder.REMOVE_STEP', 'Remove this step')}
                    </button>
                  </>
                )}
              </div>
            ) : (
              <div className="wf-builder__content-empty">
                <p className="wf-builder__empty-title">
                  {hasAnything
                    ? i18n._t('WorkflowBuilder.SELECT_STEP', 'Select a step')
                    : i18n._t('WorkflowBuilder.NO_STEPS', 'No steps yet')}
                </p>
                <p className="wf-builder__empty-desc">
                  {hasAnything
                    ? i18n._t(
                      'WorkflowBuilder.SELECT_STEP_HELP',
                      'Click a step in a stage on the left to configure who reviews, what the email says, and more.'
                    )
                    : i18n._t('WorkflowBuilder.NO_STEPS_HELP', 'Add your first review stage on the left to get started.')}
                </p>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
