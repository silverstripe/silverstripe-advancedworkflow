// Shared Babel config. Used by babel-jest for the unit tests; the
// webpack build passes the same presets to babel-loader inline.
module.exports = {
  presets: ['@babel/preset-env', '@babel/preset-react'],
};
