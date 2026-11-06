// The pure model (src/lib) is framework-free, so a Node test
// environment is enough — no jsdom required.
module.exports = {
  testEnvironment: 'node',
  testMatch: ['**/tests/**/*.test.js'],
};
