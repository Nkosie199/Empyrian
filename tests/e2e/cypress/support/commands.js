Cypress.Commands.add('restGet', (path) => cy.request(`/wp-json/wp/v2/${path}`).its('body'))

Cypress.Commands.add('termId', (taxonomy, slug) => cy.restGet(`${taxonomy}?slug=${slug}&_fields=id`).then((terms) => terms[0].id))

Cypress.Commands.add('allPosts', () =>
  cy.restGet('posts?per_page=100&_fields=id,link,title,genre,artist,meta.type,meta.post,meta.stream,meta.duration').then((first) =>
    cy.restGet('posts?per_page=100&page=2&_fields=id,link,title,genre,artist,meta.type,meta.post,meta.stream,meta.duration').then((second) => first.concat(second))
  )
)

Cypress.Commands.add('tracks', () => cy.allPosts().then((posts) => posts.filter((p) => !p.meta.type || p.meta.type === 'single')))

// Fetches as a signed-out visitor: cy.request would send the signed-in session cookies.
Cypress.Commands.add('anonymousStatus', (url) =>
  cy.window().then((win) => win.fetch(url, { credentials: 'omit', cache: 'no-store' }).then((r) => r.status))
)

Cypress.Commands.add('s3Status', (url) =>
  cy.request({ url, headers: { Range: 'bytes=0-1' }, failOnStatusCode: false }).its('status')
)

Cypress.Commands.add('signInWithMynger', () => {
  const username = Cypress.env('MYNGER_USERNAME')
  const password = Cypress.env('MYNGER_PASSWORD')
  expect(username, 'MYNGER_USERNAME in cypress.env.json').to.be.a('string')
  cy.session(['mynger', username], () => {
    cy.visit('/?mynger_sso=1')
    cy.origin('https://api.mynger.com', { args: { username, password } }, ({ username, password }) => {
      cy.get('input[name=username]').type(username)
      cy.get('input[name=password]').type(password, { log: false })
      cy.contains('button', 'Sign In').click()
    })
    cy.get('body.logged-in')
  })
})

Cypress.Commands.add('myProfilePath', () =>
  cy.visit('/').get('a[href*="/user/"]').first().invoke('attr', 'href').then((href) => new URL(href).pathname)
)
