// Stand-in for admin's i18n, which is provided at runtime by the CMS. Returns
// the English default so tests read the same copy editors see.
export default {
  _t: (key, defaultValue) => defaultValue,
  inject: (text, values) => text.replace(/{([^}]+)}/g, (match, name) => (
    Object.prototype.hasOwnProperty.call(values, name) ? String(values[name]) : match
  )),
};
