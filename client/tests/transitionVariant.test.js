import { transitionVariant, TRANSITION_VARIANT } from '../src/lib/transitionVariant';

describe('transitionVariant', () => {
  it('renders forward transitions as the primary action', () => {
    expect(transitionVariant({ isRejection: false }, true)).toBe(TRANSITION_VARIANT.PRIMARY);
  });

  it('renders backward transitions as an outline button', () => {
    expect(transitionVariant({ isRejection: false }, false)).toBe(TRANSITION_VARIANT.OUTLINE);
  });

  it('renders rejections as danger regardless of direction', () => {
    expect(transitionVariant({ isRejection: true }, true)).toBe(TRANSITION_VARIANT.DANGER);
    expect(transitionVariant({ isRejection: true }, false)).toBe(TRANSITION_VARIANT.DANGER);
  });

  it('treats a transition without the flag as a normal move', () => {
    expect(transitionVariant({}, true)).toBe(TRANSITION_VARIANT.PRIMARY);
    expect(transitionVariant(undefined, false)).toBe(TRANSITION_VARIANT.OUTLINE);
  });
});
