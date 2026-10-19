describe('Navigation', () => {
  beforeEach(() => cy.visit('/'))

  it('shows the main sections and no dead links to empty pages', () => {
    ;['Discover', 'Browse', 'Radio', 'About'].forEach((label) => cy.contains('a', new RegExp(String.raw`^\s*${label}\s*$`)))
    cy.get('a[href="https://empyrian.net/stations/"]').should('not.exist')
    cy.get('a[href*="checkout"]').should('not.exist')
  })

  it('links every menu item to a working page', () => {
    cy.get('a[href^="https://empyrian.net/"]').then(($links) => {
      const urls = [...new Set($links.toArray().map((a) => a.href.split('#')[0]))].filter((u) => !u.includes('/user/') && !u.includes('/wp-json/'))
      urls.forEach((url) => cy.request(url).its('status').should('eq', 200))
    })
  })

  it('offers Sign in with Mynger on the login page', () => {
    cy.visit('/login/')
    cy.contains('Sign in with Mynger')
  })
})
