import React from 'react';

/**
 * A form label bound to its control by id. Use it wherever the label sits
 * beside (not around) the control, so screen readers announce the label when
 * the control is focused and clicking the label focuses the control.
 *
 * @param {string} htmlFor id of the labelled control
 * @param {string} className optional class names for the label element
 * @param {React.ReactNode} children the label text
 */
export default function FieldLabel({ htmlFor, className, children }) {
  // The airbnb config asserts both nesting and htmlFor; this label is bound by
  // htmlFor alone because the control renders beside it, not inside it.
  return (
    // eslint-disable-next-line jsx-a11y/label-has-associated-control
    <label htmlFor={htmlFor} className={className}>
      {children}
    </label>
  );
}
