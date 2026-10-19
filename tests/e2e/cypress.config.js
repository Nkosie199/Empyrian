const { defineConfig } = require('cypress')

module.exports = defineConfig({
  e2e: {
    baseUrl: 'https://empyrian.net',
    specPattern: 'cypress/e2e/**/*.cy.js',
    supportFile: 'cypress/support/e2e.js',
    video: false,
    viewportWidth: 1440,
    viewportHeight: 900,
    defaultCommandTimeout: 20000,
    pageLoadTimeout: 90000,
    retries: { runMode: 1, openMode: 0 },
    env: { S3_BASE: 'https://myngerbucket14bd7-main.s3.eu-west-1.amazonaws.com' }
  }
})
