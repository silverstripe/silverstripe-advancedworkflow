import React, { useEffect, useState, useCallback } from 'react';
import Icon from './Icon';
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
import { CONFIG_FIELDS, TRANSITION_TYPES, metaFor } from '../lib/stepMeta';

const API_BASE = '/workflow-admin';

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
  const [isSaving, setIsSaving] = useState(false);
  const [saveMessage, setSaveMessage] = useState(null);

  const loadMeta = useCallback(() => {
    fetch(`${API_BASE}/workflowMeta`)
      .then((res) => res.json())
      .then(setMeta)
      .catch(() => {});
  }, []);

  // Server-side user search (users aren't preloaded — see workflowMeta).
  const searchUsers = useCallback(
    (term) => fetch(`${API_BASE}/searchUsers?term=${encodeURIComponent(term)}`)
      .then((res) => res.json())
      .then((list) => (Array.isArray(list) ? list : []))
      .catch(() => []),
    []
  );

  const loadDefinition = useCallback(() => {
    if (!definitionId) return;

    fetch(`${API_BASE}/loadDefinition?ID=${definitionId}`)
      .then((res) => res.json())
      .then((data) => {
        if (!data || data.error) return;
        setUserLabels(data.userLabels || {});
        setSteps(Array.isArray(data.steps) ? data.steps : []);
      })
      .catch(() => {});
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
      const response = await fetch(`${API_BASE}/saveWorkflow`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          id: definitionId || null,
          steps: steps.map((s) => ({
            id: s.id,
            title: s.title,
            type: s.type,
            config: s.config || {},
            transitions: s.transitions || [],
          })),
        }),
      });

      if (!response.ok) throw new Error('Save failed');
      setSaveMessage({ ok: true, text: 'Saved' });
      setTimeout(() => setSaveMessage(null), 3000);
    } catch {
      setSaveMessage({ ok: false, text: 'Failed to save' });
    } finally {
      setIsSaving(false);
    }
  };

  const selectedStep = steps.find((s) => s.id === selectedId);
  const selectedConfig = selectedStep ? CONFIG_FIELDS[selectedStep.type] : null;
  const selectedIsManaged = selectedStep ? managedIds.has(selectedStep.id) : false;

  // ---- Right-panel config editors (shared with raw steps) ----

  const renderMultiSelect = (source, selectedIds, onChange) => {
    if (source === 'users') {
      return (
        <MultiSelect
          value={selectedIds}
          onChange={onChange}
          onSearch={searchUsers}
          labels={userLabels}
          placeholder="Search users…"
        />
      );
    }

    return (
      <MultiSelect
        options={meta.groups || {}}
        value={selectedIds}
        onChange={onChange}
        placeholder="Search groups…"
      />
    );
  };

  const renderConfigField = (step, field) => {
    const value = (step.config || {})[field.key];

    if (field.input === 'multiselect') {
      return renderMultiSelect(field.source, value, (ids) => updateConfig(step.id, field.key, ids));
    }

    if (field.input === 'select') {
      return (
        <select value={value ?? ''} onChange={(e) => updateConfig(step.id, field.key, e.target.value)}>
          {field.options.map((opt) => (
            <option key={opt.value} value={opt.value}>{opt.label}</option>
          ))}
        </select>
      );
    }

    if (field.input === 'checkbox') {
      return (
        <label className="wf-builder__checkbox">
          <input
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
          value={value ?? ''}
          onChange={(e) => updateConfig(step.id, field.key, e.target.value)}
          rows={4}
        />
      );
    }

    if (field.input === 'number') {
      return (
        <input
          type="number"
          min="0"
          value={value ?? 0}
          onChange={(e) => updateConfig(step.id, field.key, parseInt(e.target.value, 10) || 0)}
        />
      );
    }

    return (
      <input
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
        <summary>Advanced: type &amp; permissions</summary>
        <div className="wf-builder__edit-row">
          <label>Transition type</label>
          <select
            value={transition.transitionType || 'Active'}
            onChange={(e) => patchTransition(step, role, { transitionType: e.target.value })}
          >
            {TRANSITION_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
          </select>
        </div>
        <div className="wf-builder__edit-row">
          <label>Restrict to groups</label>
          <p className="wf-builder__field-help">Leave empty to allow anyone assigned to the step.</p>
          {renderMultiSelect('groups', transition.groupIds, (ids) => patchTransition(step, role, { groupIds: ids }))}
        </div>
        <div className="wf-builder__edit-row">
          <label>Restrict to users</label>
          {renderMultiSelect('users', transition.userIds, (ids) => patchTransition(step, role, { userIds: ids }))}
        </div>
      </details>
    );
  };

  const renderTransitionRow = (step, role, labelNode, emptyOption) => (
    <div className="wf-builder__edit-row wf-builder__edit-row--branch">
      <label>{labelNode}</label>
      <select
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
      return <p className="wf-builder__field-help">This step ends the workflow as rejected.</p>;
    }

    if (step.type === STEP_TYPES.APPROVAL) {
      return (
        <>
          {renderTransitionRow(step, 'approve', <><Icon name="check" /> If approved, go to:</>, 'Next step in order')}
          {renderTransitionRow(step, 'reject', <><Icon name="cross" /> If rejected, go to:</>, 'End workflow (reject)')}
        </>
      );
    }

    return renderTransitionRow(step, 'next', <><Icon name="arrow-down" /> Then go to:</>, 'End workflow');
  };

  // ---- Left column pieces ----

  const renderInsertPoint = (afterStageId, beforeStageId, key) => (
    <div className="wf-builder__insert" key={key}>
      <button
        type="button"
        className="wf-builder__insert-btn"
        onClick={() => insertReviewBetween(afterStageId, beforeStageId)}
      >
        <Icon name="plus" /> Insert a review stage here
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
        role="button"
        tabIndex={0}
        aria-selected={selectedId === step.id}
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
          <span className="wf-builder__heading">Visual Workflow Builder</span>
          <p className="wf-builder__intro">
            A workflow is a series of <strong>review stages</strong>. Each stage assigns reviewers,
            notifies them, then <strong>waits for them to approve or reject</strong>. Approved content
            moves to the next stage; rejected content follows the rejection path. Add a stage to begin —
            the standard steps are created for you.
          </p>
        </div>
        <div className="wf-builder__actions">
          {saveMessage && (
            <span className={`wf-builder__msg ${saveMessage.ok ? '' : 'wf-builder__msg--error'}`}>
              <Icon name={saveMessage.ok ? 'check' : 'cross'} /> {saveMessage.text}
            </span>
          )}
          <button className="wf-builder__btn wf-builder__btn--save" onClick={saveWorkflow} disabled={isSaving} type="button">
            {isSaving ? 'Saving…' : 'Save Workflow'}
          </button>
        </div>
      </div>

      <div className="wf-builder__columns">
        <div className="wf-builder__flow">
          {!hasAnything && (
            <div className="wf-builder__flow-empty">
              <p className="wf-builder__empty-title">No stages yet</p>
              <p className="wf-builder__empty-desc">
                Start by adding a review stage. We&apos;ll create the standard steps —
                assign reviewers, notify them, and wait for approval — and a rejection path,
                all wired together. You can fine-tune each step afterwards.
              </p>
              <button type="button" className="wf-builder__btn wf-builder__btn--primary" onClick={addReviewStageAtEnd}>
                <Icon name="plus" /> Add your first review stage
              </button>
            </div>
          )}

          {model.stages.length > 0 && renderStages()}

          {hasAnything && (
            <div className="wf-builder__flow-actions">
              <button type="button" className="wf-builder__btn wf-builder__btn--primary" onClick={addReviewStageAtEnd}>
                <Icon name="plus" /> Add a review stage
              </button>
              {!hasPublishStage && (
                <button type="button" className="wf-builder__btn" onClick={addPublishStage}>
                  <Icon name="publish" /> Add “publish when approved”
                </button>
              )}
            </div>
          )}

          {model.approvedOutcome.length > 0 && (
            <div className="wf-builder__section wf-builder__section--approved">
              <div className="wf-builder__section-label">When approved</div>
              <p className="wf-builder__section-help">
                These steps run after the final approval — for example, letting the author know
                their content passed. Click one to edit it.
              </p>
              {model.approvedOutcome.map(renderRawStepRow)}
            </div>
          )}

          {model.rejection.length > 0 && (
            <div className="wf-builder__section wf-builder__section--reject">
              <div className="wf-builder__section-label">Rejection path</div>
              <p className="wf-builder__section-help">
                These steps run only when a reviewer rejects the content. Every stage&apos;s
                “reject” outcome leads here.
              </p>
              {model.rejection.map(renderRawStepRow)}
            </div>
          )}

          {model.otherSteps.length > 0 && (
            <div className="wf-builder__section wf-builder__section--other">
              <div className="wf-builder__section-label">Other steps</div>
              <p className="wf-builder__section-help">
                Steps that aren&apos;t part of a standard review stage. Click one to edit it and
                set where it leads.
              </p>
              {model.otherSteps.map(renderRawStepRow)}
            </div>
          )}

          {hasAnything && (
            <details className="wf-builder__advanced-add">
              <summary>Add an advanced step</summary>
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
                <label>Step name</label>
                <input
                  value={selectedStep.title}
                  onChange={(e) => updateStep(selectedStep.id, { title: e.target.value })}
                />
              </div>

              {selectedConfig && selectedConfig.length > 0 && (
                <>
                  <div className="wf-builder__edit-divider" />
                  {selectedConfig.map((field) => (
                    <div className="wf-builder__edit-row" key={field.key}>
                      {field.input !== 'checkbox' && <label>{field.label}</label>}
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
                    <Icon name="circle" /> This step is part of a review stage — the builder wires it up
                    automatically. Use the stage card on the left to add, remove or reorder its steps.
                  </p>
                </>
              ) : (
                <>
                  <div className="wf-builder__edit-divider" />
                  <label>What happens after this step?</label>
                  {renderRawTransitions(selectedStep)}

                  <div className="wf-builder__edit-divider" />
                  <button
                    className="wf-builder__btn wf-builder__btn--delete"
                    onClick={() => removeRawStep(selectedStep.id)}
                    type="button"
                  >
                    Remove this step
                  </button>
                </>
              )}
            </div>
          ) : (
            <div className="wf-builder__content-empty">
              <p className="wf-builder__empty-title">{hasAnything ? 'Select a step' : 'No steps yet'}</p>
              <p className="wf-builder__empty-desc">
                {hasAnything
                  ? 'Click a step in a stage on the left to configure who reviews, what the email says, and more.'
                  : 'Add your first review stage on the left to get started.'}
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
