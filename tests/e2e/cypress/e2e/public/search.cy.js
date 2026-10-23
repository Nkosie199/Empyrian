describe('Search', () => {
  it('finds a track by its title', () => {
    cy.visit('/?s=skyscraper')
    cy.contains('Skyscraper')
  })
})
