const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
  entry: {
    bundle: './src/main.js',
    styles: './src/styles/workflow.scss'
  },

  output: {
    path: path.resolve(__dirname, 'dist'),
    filename: '[name].js'
  },

  externals: {
    jquery: 'jQuery',
  },

  module: {
    rules: [
      {
        test: /\.js$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: ['@babel/preset-env', '@babel/preset-react']
          }
        }
      },
      {
        test: /\.scss$/,
        use: [
          MiniCssExtractPlugin.loader,
          'css-loader',
          'sass-loader'
        ]
      }
    ]
  },

  plugins: [
    new MiniCssExtractPlugin({
      filename: 'styles.css'
    })
  ]
};
