// Stand-in for admin's lib/Config, which is provided at runtime by the CMS.
const config = {
  SecurityID: 'test-token',
  sections: [],
};

export default {
  get: (key) => config[key],
  getSection: (name) => config.sections.find((section) => section.name === name),
  setSections: (sections) => {
    config.sections = sections;
  },
};
