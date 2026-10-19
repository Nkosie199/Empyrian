describe('Phone layout', () => {
  beforeEach(() => cy.viewport(390, 844))

  ;['/', '/browse/', '/genres/', '/artists/', '/radio/'].forEach((path) => {
    it(`fits ${path} without sideways scrolling`, () => {
      cy.visit(path)
      cy.document().then((doc) => expect(doc.documentElement.scrollWidth).to.be.lte(390))
    })
  })
})
