// Needs MYNGER_USERNAME / MYNGER_PASSWORD (a non-admin Mynger account) in cypress.env.json.
describe('Member upload lifecycle', () => {
  const title = `E2E upload ${Date.now()}`
  let pageUrl
  let streamUrl

  beforeEach(() => cy.signInWithMynger())

  const openPostMenu = (item) => {
    cy.get('main .btn-more:visible, article .btn-more:visible').first().click()
    cy.contains('a:visible, button:visible', new RegExp(String.raw`^\s*${item}\s*$`, 'i')).click()
  }

  it('uploads a track to the member\'s own S3 folder and publishes it', () => {
    cy.contains('a', /^\s*Upload\s*$/).invoke('attr', 'href').then((href) => cy.visit(href))
    cy.get('input[name=stream_file]').selectFile('cypress/fixtures/piano-note.mp3', { force: true })
    cy.get('form input[name=stream]', { timeout: 90000 })
      .should(($i) => {
        expect($i.val().startsWith(`${Cypress.env('S3_BASE')}/`)).to.equal(true)
        expect($i.val()).to.match(/piano-note-[0-9]+[.]mp3$/)
      })
      .invoke('val')
      .then((url) => { streamUrl = url })
    cy.get('form input[name=duration]').should(($i) => expect($i.val()).to.match(/^0:0[1-9]$/))
    cy.get('form input[name=image]').selectFile('cypress/fixtures/wonder-bread-poster.jpg', { force: true })
    cy.get('form input[name=title]').clear().type(title)
    cy.get('form input[name=post_status][value=publish]').check({ force: true })
    cy.get('form [name=wp-submit]').click()
    cy.location('pathname', { timeout: 60000 }).should('match', /^\/[0-9]{4}\/[0-9]{2}\/[0-9]{2}\//)
    cy.location('href').then((href) => { pageUrl = href })
  })

  it('shows the published track to everyone', () => {
    cy.anonymousStatus(pageUrl).should('eq', 200)
    cy.s3Status(streamUrl).should('be.oneOf', [200, 206])
  })

  it('lets the member edit it and make it private', () => {
    cy.visit(pageUrl)
    openPostMenu('Edit')
    cy.get('form input[name=title]:visible').clear().type(`${title} edited`)
    cy.get('form input[name=post_status][value=private]').check({ force: true })
    cy.get('form [name=wp-submit]:visible').click()
    cy.contains(`${title} edited`)
    cy.anonymousStatus(pageUrl).should('eq', 404)
  })

  it('removes it and deletes the file from S3', () => {
    cy.visit(pageUrl)
    openPostMenu('Remove')
    cy.get('.modal:visible, [role=dialog]:visible').contains('a, button', /^\s*Remove\s*$/).click()
    cy.location('pathname', { timeout: 60000 }).should('match', /^\/user\//)
    cy.anonymousStatus(pageUrl).should('eq', 404)
    cy.s3Status(streamUrl).should('be.oneOf', [403, 404])
  })
})
