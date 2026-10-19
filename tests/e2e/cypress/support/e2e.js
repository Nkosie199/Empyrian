import './commands'

// The live site loads third-party scripts (analytics, YouTube) whose errors are not ours to fail on.
Cypress.on('uncaught:exception', () => false)
