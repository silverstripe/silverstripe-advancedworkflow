// Stand-in for the CMS's global jQuery; rendering never calls it.
export default () => {
  throw new Error('jQuery is not available in tests');
};
