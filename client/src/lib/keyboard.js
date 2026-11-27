const ACTIVATION_KEYS = Object.freeze(['Enter', ' ']);

/**
 * Builds a keydown handler that gives a `role="button"` element the same
 * keyboard activation as a native button (Enter or Space).
 *
 * Key presses that originate in a nested control (e.g. a remove button inside
 * a step card) are ignored so they only trigger that control.
 *
 * @param {Function} handler called when the element itself is activated
 * @returns {Function} keydown event handler
 */
export default function onActivateKey(handler) {
  return (event) => {
    if (event.target !== event.currentTarget || !ACTIVATION_KEYS.includes(event.key)) {
      return;
    }

    event.preventDefault();
    handler(event);
  };
}
