import React, { useEffect, useState, useCallback } from 'react';

const CURVE_OFFSET = 60;

export default function ConnectionLayer({ steps }) {
  const [lines, setLines] = useState([]);

  const recalculate = useCallback(() => {
    const container = document.querySelector('.workflow-builder__canvas');
    if (!container) return;

    const offset = container.getBoundingClientRect();
    const newLines = [];

    steps.forEach((step) => {
      const from = document.getElementById(`step-${step.id}`);
      if (!from || !step.branches) return;

      const fromRect = from.getBoundingClientRect();

      const connect = (targetId, type) => {
        const to = document.getElementById(`step-${targetId}`);
        if (!to) return;

        const toRect = to.getBoundingClientRect();

        newLines.push({
          x1: fromRect.right - offset.left,
          y1: fromRect.top + fromRect.height / 2 - offset.top,
          x2: toRect.left - offset.left,
          y2: toRect.top + toRect.height / 2 - offset.top,
          type,
        });
      };

      if (step.branches.approve?.nextStepId) {
        connect(step.branches.approve.nextStepId, 'approve');
      }

      if (step.branches.reject?.nextStepId) {
        connect(step.branches.reject.nextStepId, 'reject');
      }
    });

    setLines(newLines);
  }, [steps]);

  useEffect(() => {
    recalculate();

    const observer = new MutationObserver(recalculate);
    const canvas = document.querySelector('.workflow-builder__canvas');

    if (canvas) {
      observer.observe(canvas, { childList: true, subtree: true });
    }

    return () => observer.disconnect();
  }, [recalculate]);

  return (
    <svg className="workflow-builder__connections" aria-hidden="true">
      {lines.map((l, i) => (
        <path
          key={i}
          d={`M ${l.x1} ${l.y1}
              C ${l.x1 + CURVE_OFFSET} ${l.y1},
                ${l.x2 - CURVE_OFFSET} ${l.y2},
                ${l.x2} ${l.y2}`}
          className={`workflow-builder__connection workflow-builder__connection--${l.type}`}
        />
      ))}
    </svg>
  );
}
