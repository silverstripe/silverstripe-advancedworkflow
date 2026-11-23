/**
 * Button variants for workflow transitions, named after the CMS styleguide
 * buttons they render as.
 */
export const TRANSITION_VARIANT = Object.freeze({
  PRIMARY: 'primary',
  DANGER: 'danger',
  OUTLINE: 'outline',
});

/**
 * A rejection is destructive wherever it sits in the workflow, so it wins over
 * direction; other forward moves are the primary action and moves back are
 * secondary.
 *
 * @param {{isRejection?: boolean}} transition
 * @param {boolean} isForward whether the transition moves the workflow on
 * @returns {string} one of TRANSITION_VARIANT
 */
export const transitionVariant = (transition, isForward) => {
  if (transition && transition.isRejection) {
    return TRANSITION_VARIANT.DANGER;
  }

  return isForward ? TRANSITION_VARIANT.PRIMARY : TRANSITION_VARIANT.OUTLINE;
};
